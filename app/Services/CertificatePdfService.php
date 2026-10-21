<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Throwable;

/**
 * Certificate のPDFを生成し、privateディスクへ保存するService。
 *
 * DB更新やトランザクションは担当せず、保存に成功した相対パスを返す。
 * 呼び出し元は返却値を使って後続処理と補償削除を管理する。
 */
final class CertificatePdfService
{
    /**
     * CertificateからPDFを生成して保存する。
     *
     * @throws Throwable PDF生成、保存、保存後検証の失敗
     */
    public function generateAndStore(Certificate $certificate): string
    {
        $disk = Storage::disk('private');
        $path = $certificate->pdf_path;
        $storageAttempted = false;

        try {
            if ($disk->exists($path)) {
                throw new \RuntimeException('指定された修了証PDFの保存先は既に存在します。');
            }

            $certificate->loadMissing(['user', 'certification']);

            $html = view('certificates.pdf', compact('certificate'))->render();

            $pdf = new Mpdf($this->mpdfConfiguration());
            $pdf->WriteHTML($html);
            $binary = $pdf->OutputBinaryData();

            if ($binary === '' || ! str_starts_with($binary, '%PDF-')) {
                throw new \RuntimeException('修了証PDFの生成結果が不正です。');
            }

            $storageAttempted = true;
            if ($disk->put($path, $binary) !== true) {
                throw new \RuntimeException('修了証PDFを保存できませんでした。');
            }

            if (! $disk->exists($path) || $disk->size($path) <= 0) {
                throw new \RuntimeException('保存した修了証PDFを検証できませんでした。');
            }

            return $path;
        } catch (Throwable $exception) {
            if ($storageAttempted) {
                try {
                    if ($disk->delete($path) !== true) {
                        Log::warning('修了証PDFの補償削除に失敗しました。');
                    }
                } catch (Throwable) {
                    try {
                        Log::warning('修了証PDFの補償削除に失敗しました。');
                    } catch (Throwable) {
                        // ログ失敗でも元の生成・保存例外を隠さない。
                    }
                }
            }

            throw $exception;
        }
    }

    private function mpdfConfiguration(): array
    {
        return [
            'mode' => 'ja',
            'format' => 'A4',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ];
    }
}
