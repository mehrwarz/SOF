<?php
class SmtpMailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail = '';
    private ?string $fromName = null;
    private string $toEmail = '';
    private string $subject = '';
    private string $body = '';
    private $socket = null;

    public function __construct(string $host, int $port, string $username, string $password)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function setFrom(string $email, ?string $name = null): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$email}");
        }
        $this->fromEmail = $email;
        $this->fromName = $name;
    }

    public function setTo(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$email}");
        }
        $this->toEmail = $email;
    }

    // ... other setters unchanged

    private function connect(): bool
    {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new RuntimeException("Could not connect to SMTP host: ($errno) $errstr");
        }
        stream_set_timeout($this->socket, 10);
        if (!$this->readResponse(220)) {
            throw new RuntimeException("Failed to receive 220 Service Ready message");
        }
        return true;
    }

    private function buildMessage(): string
    {
        $from = $this->fromName ? "\"{$this->fromName}\" <{$this->fromEmail}>" : $this->fromEmail;
        $headers = "From: {$from}\r\n";
        $headers .= "To: {$this->toEmail}\r\n";
        $headers .= "Subject: {$this->subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        return $headers . "\r\n" . $this->body;
    }

    // ... other methods updated similarly with exception handling
}

function sendMail($to, $subject, $message): bool
{
    try {
        $mailer = new SmtpMailer(
            getenv('SMTP_HOST') ?: 'smtp.office365.com',
            587,
            getenv('SMTP_USERNAME') ?: 'tamimullah.azizi@autismbts.com',
            getenv('SMTP_PASSWORD') ?: 'your_password'
        );
        $mailer->setFrom('info@autismbts.com', 'HMIS Mailer');
        $mailer->setTo($to);
        $mailer->setSubject($subject);
        $mailer->setBody($message);
        return $mailer->send();
    } catch (Exception $error) {
        error_log("Failed to send email: " . $error->getMessage());
        return false;
    }
}
