<?php

namespace App\Http\Controllers;

use App\Services\GoogleDocConverter;
use App\Services\LocalFileConverter;
use App\Http\Controllers\Controller;
use Google_Service_Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class DocConvertController extends Controller
{
    public function __construct(
        private GoogleDocConverter $converter,
        private LocalFileConverter $localConverter
    )
    {
    }

    public function index()
    {
        return view('doc-converter');
    }

    public function convert(Request $request)
    {
        $data = $request->validate([
            'url' => ['nullable', 'url', 'required_without:file'],
            'file' => ['nullable', 'file', 'required_without:url', 'mimes:docx,pdf,xlsx', 'max:40960'],
            'upload_format' => ['nullable', 'string', 'in:pdf,xlsx,json,md'],
        ]);

        if ($request->hasFile('file')) {
            try {
                $upload = $this->localConverter->prepareUpload($request->file('file'));
            } catch (RuntimeException $e) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'errors' => ['file' => [$e->getMessage()]],
                    ], 422);
                }

                return back()
                    ->withErrors(['file' => $e->getMessage()])
                    ->withInput();
            }

            $requestedFormat = $request->input('upload_format');
            $defaultFormat = $upload['formats'][0] ?? null;

            if ($requestedFormat) {
                if (!in_array($requestedFormat, $upload['formats'], true)) {
                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'message' => 'Selected format is not available for this file type.',
                            'errors' => ['upload_format' => ['Selected format is not available for this file type.']],
                        ], 422);
                    }

                    return back()
                        ->withErrors(['upload_format' => 'Selected format is not available for this file type.'])
                        ->withInput();
                }

                $defaultFormat = $requestedFormat;
            }

            if ($defaultFormat === null) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'message' => 'No export format is available for this file.',
                        'errors' => ['file' => ['No export format is available for this file.']],
                    ], 422);
                }

                return back()
                    ->withErrors(['file' => 'No export format is available for this file.'])
                    ->withInput();
            }

            if ($requestedFormat === null) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'message' => 'Please choose an output format.',
                        'errors' => ['upload_format' => ['Please choose an output format.']],
                    ], 422);
                }

                return back()
                    ->withErrors(['upload_format' => 'Please choose an output format.'])
                    ->withInput();
            }

            $downloadUrl = URL::temporarySignedRoute(
                'upload.download',
                now()->addMinutes(5),
                ['token' => $upload['token'], 'format' => $defaultFormat]
            );

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'download_url' => $downloadUrl,
                    'format' => $defaultFormat,
                ]);
            }

            return redirect()->to($downloadUrl);
        }

        try {
            $fileId = $this->converter->extractFileIdFromUrl($data['url']);
            $this->converter->warmCache($fileId);
        } catch (Google_Service_Exception $e) {
            if ((int) $e->getCode() === 403) {
                return back()
                    ->withErrors(['url' => "Ensure your file is set to 'Anyone with the link can view'."])
                    ->withInput();
            }

            return back()
                ->withErrors(['url' => 'Google Drive rejected the request.'])
                ->withInput();
        } catch (RuntimeException $e) {
            return back()
                ->withErrors(['url' => $e->getMessage()])
                ->withInput();
        }

        $formats = [
            'pdf' => 'PDF',
            'docx' => 'DOCX',
            'odt' => 'ODT',
            'xlsx' => 'XLSX',
            'html' => 'HTML',
            'md' => 'MD',
            'json' => 'JSON',
        ];

        $downloads = [];
        foreach ($formats as $key => $label) {
            $downloads[] = [
                'label' => $label,
                'url' => URL::temporarySignedRoute(
                    'doc.download',
                    now()->addMinutes(5),
                    ['fileId' => $fileId, 'format' => $key]
                ),
            ];
        }

        return view('doc-converter', [
            'downloads' => $downloads,
            'fileId' => $fileId,
        ]);
    }

    public function download(string $fileId, string $format)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{10,}$/', $fileId)) {
            abort(404);
        }

        try {
            return $this->converter->streamFormat($fileId, $format);
        } catch (RuntimeException $e) {
            abort(400, $e->getMessage());
        }
    }

    public function downloadUpload(string $token, string $format)
    {
        try {
            return $this->localConverter->streamUploadFormat($token, $format);
        } catch (RuntimeException $e) {
            abort(400, $e->getMessage());
        }
    }
}
