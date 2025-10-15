<?php
ini_set("display_errors", "on");

include "smtpMailer.php";

$smtpHost = 'smtp.office365.com';
$smtpPort = 587; 
$smtpUser = 'mehrwarz@live.com';
$smtpPass = 'rayD4me@'; 

$mailer = new SmtpMailer($smtpHost, $smtpPort, $smtpUser, $smtpPass);

$mailer->setFrom('sender@example.com');
$mailer->setTo('emehrwarz@gmail.com.com');
$mailer->setSubject('Test Email from Custom PHP Mailer');
$mailer->setBody("Hello,\n\nThis is a test email sent using a custom PHP SMTP class with TLS encryption.\n\nBest regards,\nYour App");

if ($mailer->send()) {
    echo "\nEmail sent successfully!\n";
} else {
    echo "\nEmail sending failed. Check the error messages above.\n";
}