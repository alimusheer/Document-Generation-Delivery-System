<?php

declare(strict_types=1);

function generate_pdf(string $html, string $tmpPath): bool
{
    try {
        $tmpDir = dirname($tmpPath);
        $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmpDir]);
        $mpdf->WriteHTML($html);
        $mpdf->Output($tmpPath, 'F'); // 'F' saves the file to the server
        return true;
    } catch (\Throwable $e) {
        write_log('PDF', get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
        return false;
    }
}
