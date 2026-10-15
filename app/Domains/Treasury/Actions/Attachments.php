<?php

namespace App\Domains\Treasury\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Treasury\Models\Attachment;
use App\Domains\Treasury\Models\Operation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class Attachments
{
    public function upload(int $lodgeId, int $operationId, UploadedFile $file): Attachment
    {
        $access = app(Access::class);
        $source = $access->guard($lodgeId, 'update');
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png']])->validate();
        $path = null;
        try {
            return DB::transaction(function () use ($access, $source, $lodgeId, $operationId, $file, &$path): Attachment {
                $access->lock($lodgeId, $source->obedience_id, 'update');
                Operation::query()->where('lodge_id', $lodgeId)->whereKey($operationId)->firstOrFail();
                $bytes = $file->getContent();
                $path = 'treasury/'.$lodgeId.'/'.$operationId.'/'.bin2hex(random_bytes(24));
                if (! Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($bytes)))) {
                    throw new \RuntimeException('Allegato non salvato.');
                }
                $a = Attachment::query()->create(['lodge_id' => $lodgeId, 'operation_id' => $operationId, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 160), 'path' => $path, 'mime' => $file->getMimeType(), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
                Audit::record('treasury.attachment.added', VerifiedSession::user()->id, $lodgeId, metadata: ['attachment_id' => $a->id]);

                return $a;
            }, 1);
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
    }

    /** @return array{record:Attachment,bytes:string} */
    public function download(int $lodgeId, int $id): array
    {
        app(Access::class)->guard($lodgeId);
        $a = Attachment::query()->where('lodge_id', $lodgeId)->whereKey($id)->firstOrFail();
        $encrypted = Storage::disk('local')->get($a->path);
        abort_unless(is_string($encrypted), 404);
        $bytes = base64_decode(Crypt::decryptString($encrypted), true);
        abort_unless(is_string($bytes) && hash_equals($a->sha256, hash('sha256', $bytes)), 500);
        Audit::record('treasury.attachment.downloaded', VerifiedSession::user()->id, $lodgeId, metadata: ['attachment_id' => $a->id]);

        return ['record' => $a, 'bytes' => $bytes];
    }
}
