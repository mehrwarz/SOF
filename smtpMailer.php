<?php

class SmtpMailer {
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail = '';
    private string $toEmail = '';
    private string $subject = '';
    private string $body = '';
    private $socket = null;

    public function __construct(string $host, int $port, string $username, string $password) {
        $this->host = "ssl://{$host}";
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function setFrom(string $email): void {
        $this->fromEmail = $email;
    }

    public function setTo(string $email): void {
        $this->toEmail = $email;
    }
    
    public function setSubject(string $subject): void {
        $this->subject = $subject;
    }
    
    public function setBody(string $body): void {
        $this->body = $body;
    }

    
    private function connect(): bool {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 30);

        if (!$this->socket) {
            echo "ERROR: Could not connect to SMTP host: ($errno) $errstr\n";
            return false;
        }

        // Wait for 220 Service Ready message
        if (!$this->readResponse(220)) {
            return false;
        }

        return true;
    }
    
    private function executeCommand(string $command, int $expectedCode): bool {
        if (!fwrite($this->socket, $command)) {
            echo "ERROR: Failed to write command to socket.\n";
            return false;
        }
        return $this->readResponse($expectedCode);
    }


    private function readResponse(int $expectedCode): bool {
        $response = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 515); // Read a line
            $response .= $line;

            // Check if this is the last line of the response (e.g., starts with "250 ")
            if (isset($line[3]) && $line[3] == ' ') {
                break;
            }
        }

        if (empty($response)) {
            echo "ERROR: Empty response from server.\n";
            return false;
        }

        $code = (int)substr($response, 0, 3);

        if ($code !== $expectedCode) {
            echo "ERROR: Unexpected response code: {$code}. Full response:\n{$response}\n";
            return false;
        }

        return true;
    }

    /**
     * Builds the full MIME message headers and content.
     */
    private function buildMessage(): string
    {
        $headers = "From: {$this->fromEmail}\r\n";
        $headers .= "To: {$this->toEmail}\r\n";
        $headers .= "Subject: {$this->subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        // Separate headers from body with an empty line
        return $headers . "\r\n" . $this->body;
    }


    private function authenticate(): bool {
        // EHLO or HELO command (Extended Hello)
        if (!$this->executeCommand("EHLO " . $_SERVER['SERVER_NAME'] . "\r\n", 250)) {
             // Fallback to HELO if EHLO fails (less common)
             if (!$this->executeCommand("HELO " . $_SERVER['SERVER_NAME'] . "\r\n", 250)) {
                 return false;
             }
        }

        // Send AUTH LOGIN command
        if (!$this->executeCommand("AUTH LOGIN\r\n", 334)) { // 334 means "send username"
            return false;
        }

        // Send Base64 encoded username
        if (!$this->executeCommand(base64_encode($this->username) . "\r\n", 334)) { // 334 means "send password"
            return false;
        }

        // Send Base64 encoded password
        if (!$this->executeCommand(base64_encode($this->password) . "\r\n", 235)) { // 235 means "Authentication successful"
            return false;
        }

        return true;
    }


    public function send(): bool {
        if (empty($this->fromEmail) || empty($this->toEmail) || empty($this->body)) {
            echo "ERROR: Sender, recipient, or body not set.\n";
            return false;
        }

        if (!$this->connect()) {
            return false;
        }

        if (!$this->authenticate()) {
            return false;
        }

        // 1. MAIL FROM command
        if (!$this->executeCommand("MAIL FROM:<{$this->fromEmail}>\r\n", 250)) {
            return false;
        }

        // 2. RCPT TO command
        if (!$this->executeCommand("RCPT TO:<{$this->toEmail}>\r\n", 250)) {
            return false;
        }

        // 3. DATA command
        if (!$this->executeCommand("DATA\r\n", 354)) { // 354 means "Start mail input; end with <CRLF>.<CRLF>"
            return false;
        }

        // 4. Send the message data (headers + body) and the terminating dot
        $message = $this->buildMessage();
        $data_command = $message . "\r\n.\r\n";

        if (!$this->executeCommand($data_command, 250)) { // 250 means "Requested mail action okay, completed"
            return false;
        }

        // 5. QUIT command
        $this->executeCommand("QUIT\r\n", 221); // 221 means "Service closing transmission channel"

        // Close connection
        fclose($this->socket);

        return true;
    }
}


