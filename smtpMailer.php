<?php

/**
 * Custom Exception for SMTP errors.
 * Improves error handling compared to simple echo statements.
 */
class SmtpException extends Exception {
    // TODO:
}


class RobustSmtpMailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;

    private string $fromEmail = '';
    private string $fromName = '';
    private string $toEmail = '';
    private string $toName = '';
    private string $subject = '';
    private string $body = '';
    private bool $isHtml = false;

    /** @var resource|null The socket resource */
    private $socket = null;

    public function __construct(string $host, int $port, string $username, string $password)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Sets the sender email address and optional name.
     */
    public function setFrom(string $email, string $name = ''): void
    {
        $this->fromEmail = $email;
        $this->fromName = $name;
    }

    public function setTo(string $email,string $name = ''): void
    {
        $this->toEmail = $email;
        $this->toName = $name;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    /**
     * Sets the message body and content type.
     */
    public function setBody(string $body, bool $isHtml = false): void
    {
        $this->body = $body;
        $this->isHtml = $isHtml;
    }

    /**
     * Establishes the socket connection, using ssl:// wrapper for port 465 (Implicit TLS).
     * @throws SmtpException
     */
    private function connect(): bool
    {
        // Use 'ssl://' wrapper for Implicit TLS/SSL (usually port 465)
        $protocol = ($this->port === 465) ? 'ssl://' : '';

        // Attempt connection with a generous timeout
        $this->socket = @fsockopen($protocol . $this->host, $this->port, $errno, $errstr, 30);

        if (!$this->socket) {
            throw new SmtpException("Could not connect to SMTP host: ($errno) $errstr");
        }

        // Set socket timeout for read operations
        stream_set_timeout($this->socket, 5);

        // Wait for 220 Service Ready message
        $this->readResponse(220);

        return true;
    }

    /**
     * Sends a command to the server and checks the response.
     * @param string $command The SMTP command to execute (e.g., "EHLO example.com\r\n").
     * @param int $expectedCode The expected numeric response code.
     * @throws SmtpException
     */
    private function executeCommand(string $command, int $expectedCode): void
    {
        if (!fwrite($this->socket, $command)) {
            throw new SmtpException("Failed to write command to socket: {$command}");
        }
        $this->readResponse($expectedCode);
    }

    /**
     * Reads the server's response, handling multi-line messages and checking the code.
     * @param int $expectedCode The code expected from the server.
     * @return string The full response text.
     * @throws SmtpException
     */
    private function readResponse(int $expectedCode): string
    {
        $response = '';
        $line = '';

        while (!feof($this->socket)) {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                break; // Socket error or closed
            }
            $response .= $line;

            // SMTP standard: a space (' ') at the 4th position (index 3) indicates the end of a multi-line response.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if (empty($response)) {
            throw new SmtpException("Empty response received from server.");
        }

        // Extract the code from the first three characters
        $code = (int) substr($response, 0, 3);

        if ($code !== $expectedCode) {
            // Log the sensitive AUTH details, but not the password
            $command = trim(substr($response, 4));
            throw new SmtpException("Unexpected SMTP response code: {$code} (Expected {$expectedCode}). Response: " . trim($response));
        }

        return $response;
    }

    /**
     * Builds the full MIME message headers and content.
     */
    private function buildMessage(): string
    {
        $sender = empty($this->fromName)
            ? "<{$this->fromEmail}>"
            : "=?" . 'UTF-8' . "?B?" . base64_encode($this->fromName) . "?=" . " <{$this->fromEmail}>";

        $headers = "From: {$sender}\r\n";
        $headers .= "To: $toName <{$this->toEmail}>\r\n";
        $headers .= "Subject: =?" . 'UTF-8' . "?B?" . base64_encode($this->subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        if ($this->isHtml) {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: quoted-printable\r\n";
            // Quoted-printable encoding is generally safer and better than 8bit for plain text
            $this->body = quoted_printable_encode($this->body);
        }

        // Separate headers from body with an empty line
        return $headers . "\r\n" . $this->body;
    }


    /**
     * Handles EHLO/HELO, STARTTLS (if needed), and AUTH LOGIN.
     * @throws SmtpException
     */
    private function authenticate(): void
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost'; // Safe fallback
        $hostDomain = explode('@', $this->fromEmail)[1] ?? $serverName;

        // 1. EHLO or HELO command
        $this->executeCommand("EHLO {$hostDomain}\r\n", 250);

        // 2. Explicit TLS/SSL (STARTTLS) for ports like 587
        if ($this->port !== 465) {
            $this->executeCommand("STARTTLS\r\n", 220);

            // Switch to socket connection to encrypt mode
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new SmtpException("Failed to establish TLS encryption after STARTTLS.");
            }

            // Re-issue EHLO after STARTTLS
            $this->executeCommand("EHLO {$hostDomain}\r\n", 250);
        }

        // 3. Send AUTH LOGIN command
        $this->executeCommand("AUTH LOGIN\r\n", 334); // 334 means "send username"

        // 4. Send Base64 encoded username
        $this->executeCommand(base64_encode($this->username) . "\r\n", 334); // 334 means "send password"

        // 5. Send Base64 encoded password
        $this->executeCommand(base64_encode($this->password) . "\r\n", 235); // 235 means "Authentication successful"
    }

    /**
     * Main method to connect, authenticate, and send the email.
     * @return bool True on successful send.
     * @throws SmtpException
     */
    public function send(): bool
    {
        if (empty($this->fromEmail) || empty($this->toEmail) || empty($this->body)) {
            throw new SmtpException("Sender, recipient, or body not set.");
        }

        try {
            // 1. Connect and Authenticate
            $this->connect();
            $this->authenticate();

            // 2. MAIL FROM command
            $this->executeCommand("MAIL FROM:<{$this->fromEmail}>\r\n", 250);

            // 3. RCPT TO command (add more recipients/CC/BCC here if extending functionality)
            $this->executeCommand("RCPT TO:<{$this->toEmail}>\r\n", 250);

            // 4. DATA command
            $this->executeCommand("DATA\r\n", 354); // 354 means "Start mail input; end with <CRLF>.<CRLF>"

            // 5. Send the message data (headers + body) and the terminating dot
            $message = $this->buildMessage();
            $dataCommand = $message . "\r\n.\r\n";

            // Expect 250 for success (Queued for delivery)
            $this->executeCommand($dataCommand, 250);

            // 6. QUIT command (Don't check response, just send)
            fwrite($this->socket, "QUIT\r\n");

            return true;

        } finally {
            // Ensure the socket is always closed, even if an exception was thrown
            if (is_resource($this->socket)) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }
}


/**
 * Example usage function using the improved mailer.
 * Uses exception handling for reliable error reporting.
 */
function sendMail(string $to, string $subject, string $message, bool $isHtml = false): bool|SmtpException
{
    // WARNING: Replace these placeholders with your actual SMTP credentials!
    $smtpHost = "smtp.office365.com";
    $smtpPort = 587; // Set to 587 for STARTTLS (Explicit TLS)
    $smtpUsername = "your_username@example.com";
    $smtpPassword = "YourSecurePassword";

    try {
        $mailer = new RobustSmtpMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword);

        // The setFrom method now supports a display name
        $mailer->setFrom("info@yourdomain.com", "HMIS Mailer");
        $mailer->setTo($to);
        $mailer->setSubject($subject);
        $mailer->setBody($message, $isHtml); // Use true for HTML content

        $result = $mailer->send();
        return $result;
    } catch (SmtpException $error) {
        // Log the detailed error message for debugging
        error_log("SMTP Mail Error: " . $error->getMessage());
        return $error;
    } catch (Exception $error) {
        // Catch any other general PHP exceptions
        error_log("General Mail Error: " . $error->getMessage());
        return $error;
    }
}

// --- Example Execution (Uncomment to test) ---

/*
$recipient = "test@example.com";
$emailSubject = "Test Email from Robust Mailer";
$emailBody = "<h1>Hello!</h1><p>This is a <b>robust HTML</b> test email sent via custom SMTP class.</p>";

$sendResult = sendMail($recipient, $emailSubject, $emailBody, true);

if ($sendResult === true) {
    echo "Email sent successfully!\n";
} elseif ($sendResult instanceof SmtpException) {
    echo "Email failed to send (SMTP Error):\n" . $sendResult->getMessage() . "\n";
} else {
    echo "Email failed to send (General Error):\n" . $sendResult->getMessage() . "\n";
}
*/
