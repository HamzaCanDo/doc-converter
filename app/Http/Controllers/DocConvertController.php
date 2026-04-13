<?php

namespace App\Http\Controllers;

use App\Services\GoogleDocConverter;
use App\Http\Controllers\Controller;
use Google_Service_Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class DocConvertController extends Controller
{
    public function __construct(private GoogleDocConverter $converter)
    {
    }

    public function index()
    {
        return view('doc-converter');
    }

    public function convert(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'url'],
        ]);

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
}
