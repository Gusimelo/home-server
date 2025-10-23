<?php

// Include Composer's autoloader
require 'vendor/autoload.php';

// Path to your PDF file
$pdfFilePath = '/var/www/html/despesas/pessoal/uploads/68f8040dc80686.04270183.pdf';

if (!file_exists($pdfFilePath)) {
    die("Error: File not found at '{$pdfFilePath}'");
}

// Create a new parser object
$parser = new \Smalot\PdfParser\Parser();

try {
    // Parse the file
    $pdf = $parser->parseFile($pdfFilePath);
    $text = $pdf->getText();
    echo nl2br($text); // Using nl2br to preserve line breaks in HTML output
} catch (\Exception $e) {
    echo 'An error occurred while parsing the PDF: ' . $e->getMessage();
}