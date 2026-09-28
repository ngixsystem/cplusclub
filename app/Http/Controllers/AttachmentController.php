<?php
namespace App\Http\Controllers;
use App\Models\{Attachment, Ticket, Equipment, Inspection};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Storage};
use Illuminate\Validation\Rule;
class AttachmentController extends Controller {
    public function store(Request $r) {
        $d=$r->validate(['type'=>['required',Rule::in(['ticket','equipment','inspection'])],'id'=>'required|integer','stage'=>['nullable',Rule::in(['before','after'])],'file'=>'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240']);
        $class=match($d['type']){'ticket'=>Ticket::class,'equipment'=>Equipment::class,'inspection'=>Inspection::class};
        $parent=$class::findOrFail($d['id']); Gate::authorize('view',$parent);
        abort_if($r->user()->role==='representative' && $d['type']!=='ticket',403);
        $file=$r->file('file');$path=$file->store('attachments','local');
        try {
            $a=new Attachment();$a->club_id=$parent->club_id;$a->uploaded_by=$r->user()->id;
            $field=$d['type'].'_id';$a->$field=$parent->id;$a->stage=$d['stage']??null;
            $a->path=$path;$a->name=mb_substr(basename($file->getClientOriginalName()),0,255);$a->mime=$file->getMimeType();$a->size=$file->getSize();$a->save();
        } catch(\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return back()->with('success','Вложение сохранено в приватном хранилище.');
    }
    public function download(Attachment $attachment) { Gate::authorize('view',$attachment);return Storage::disk('local')->download($attachment->path,$attachment->name,['X-Content-Type-Options'=>'nosniff']); }
}
