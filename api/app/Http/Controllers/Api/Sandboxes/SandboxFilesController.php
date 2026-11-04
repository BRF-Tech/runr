<?php

namespace App\Http\Controllers\Api\Sandboxes;

use App\Services\Sandboxes\SandboxFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files of a sandbox: list, read, write, delete, mkdir, directory up- and download as tar.
 *
 * @tags Sandboxes > Files
 */
class SandboxFilesController extends SandboxApiController
{
    public function __construct(private SandboxFiles $files) {}

    /** List the files in a sandbox directory */
    public function index(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $path = (string) $request->query('path', '/workspace');

        return response()->json(['data' => $this->files->list($row, $path)]);
    }

    /**
     * Read a file from a sandbox
     *
     * Raw bytes, or {path, size, content_base64} with format=json.
     */
    public function read(Request $request, string $sandbox): Response
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $path = SandboxFiles::normalizePath((string) $request->query('path', ''));
        $content = $this->files->read($row, $path);

        if ($request->query('format') === 'json') {
            return response()->json(['data' => ['path' => $path, 'size' => strlen($content), 'content_base64' => base64_encode($content)]]);
        }

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes(basename($path) ?: 'file', '"\\').'"',
            'X-RunR-File-Size' => (string) strlen($content),
        ]);
    }

    /**
     * Write a file in a sandbox
     *
     * Raw body, or JSON {content_base64, mode?}; missing parent directories are created.
     */
    public function write(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $path = (string) $request->query('path', $request->input('path', ''));

        if ($request->isJson()) {
            $data = $request->validate([
                'content_base64' => 'present|nullable|string',
                'mode' => 'nullable|string|max:4',
            ]);
            $content = base64_decode((string) ($data['content_base64'] ?? ''), true);
            if ($content === false) {
                return response()->json(['message' => __('sandboxes.base64_invalid'), 'errors' => ['content_base64' => [__('sandboxes.base64_invalid')]]], 422);
            }
            $mode = $data['mode'] ?? null;
        } else {
            $this->files->assertUploadSize((int) $request->header('Content-Length', 0));
            $content = $request->getContent();
            $mode = $request->query('mode');
        }

        return response()->json(['data' => $this->files->write($row, $path, $content, $mode !== null ? (string) $mode : null)]);
    }

    /** Delete a file or directory in a sandbox */
    public function destroy(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');

        return response()->json(['data' => $this->files->remove($row, (string) $request->query('path', ''), $request->boolean('recursive'))]);
    }

    /** Create a directory in a sandbox */
    public function mkdir(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $data = $request->validate(['path' => 'required|string|max:4096']);

        return response()->json(['data' => $this->files->mkdir($row, $data['path'])]);
    }

    /**
     * Download a sandbox directory's contents as a tar archive
     *
     * Tar of a directory's contents.
     */
    public function archive(Request $request, string $sandbox): Response
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $path = SandboxFiles::normalizePath((string) $request->query('path', '/workspace'));
        $tar = $this->files->archive($row, $path);

        return response($tar, 200, [
            'Content-Type' => 'application/x-tar',
            'Content-Disposition' => 'attachment; filename="'.addcslashes((basename($path) ?: 'root').'.tar', '"\\').'"',
        ]);
    }

    /** Extract a tar body into a directory. */
    public function extract(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $this->files->assertUploadSize((int) $request->header('Content-Length', 0));

        return response()->json(['data' => $this->files->extract($row, (string) $request->query('path', '/workspace'), $request->getContent())]);
    }
}
