<?php

namespace App\Http\Controllers;

use App\Services\ApiDetective\BlockedUrlException;
use App\Services\ApiDetective\Discoverer;
use App\Services\ApiDetective\DiscoveryFailed;
use App\Services\ApiDetective\SafeHttp;
use App\Services\ApiDetective\Summarizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class ApiDetectiveController extends Controller
{
    public function index()
    {
        return view('api-detective.index');
    }

    /** Bundled sample spec, so the tool can be tried without any network discovery. */
    public function demo(): Response
    {
        return response(file_get_contents(resource_path('api-detective/jsonplaceholder.openapi.yaml')), 200, ['Content-Type' => 'text/yaml; charset=utf-8']);
    }

    public function discover(Request $request, Discoverer $discoverer, Summarizer $summarizer): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:url,paste'],
            'input' => ['required', 'string', 'max:8000000'],
        ]);

        set_time_limit(90);

        try {
            $result = $data['mode'] === 'url'
                ? $discoverer->fromUrl(trim($data['input']))
                : $discoverer->fromText($data['input']);
        } catch (DiscoveryFailed $e) {
            return response()->json(['message' => $e->getMessage(), 'log' => $e->log], 422);
        }

        $result['api'] = $summarizer->annotate($result['api']);

        return response()->json($result);
    }

    /** "Try it": proxy one request through the SSRF guard, so CORS never gets in the way. */
    public function send(Request $request, SafeHttp $http): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'url' => ['required', 'string', 'max:4000'],
            'headers' => ['nullable', 'array', 'max:50'],
            'headers.*' => ['nullable', 'string', 'max:8000'],
            'body' => ['nullable', 'string', 'max:2000000'],
        ]);

        try {
            $res = $http->send($data['method'], $data['url'], $data['headers'] ?? [], $data['body'] ?? null, maxRedirects: 0, timeout: 20, maxBytes: 5 * 1024 * 1024);
        } catch (BlockedUrlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (ConnectionException $e) {
            return response()->json(['error' => 'Could not connect: '.self::short($e->getMessage())], 502);
        } catch (Throwable $e) {
            // e.g. the size guard aborting a transfer
            return response()->json(['error' => self::short($e->getMessage())], 502);
        }

        $isText = mb_check_encoding($res['body'], 'UTF-8');

        return response()->json([
            'status' => $res['status'],
            'reason' => $res['reason'],
            'headers' => $res['headers'],
            'body' => $isText ? $res['body'] : null,
            'binary' => ! $isText,
            'size' => $res['size'],
            'timeMs' => $res['timeMs'],
        ]);
    }

    private static function short(string $message): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', $message) ?? '', 0, 220, '…');
    }
}
