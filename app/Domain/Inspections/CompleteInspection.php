<?php
namespace App\Domain\Inspections;
use App\Models\{Inspection, Equipment, User, Visit};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;
class CompleteInspection {
    public const ITEMS = ['dust','case','fans','cables','monitor','peripherals','usb_audio','temperatures','damage','work','boot_game'];
    public function execute(User $actor, int $id, array $data): Inspection {
        return DB::transaction(function () use ($actor,$id,$data) {
            $inspection = Inspection::findOrFail($id);
            $visit = Visit::lockForUpdate()->findOrFail($inspection->visit_id);
            $inspection = Inspection::lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update',$inspection);
            abort_unless($visit->status === 'planned' && $inspection->status === 'pending',409,'Осмотр уже обработан или выезд завершён.');
            $equipment = Equipment::lockForUpdate()->findOrFail($inspection->equipment_id);
            if ($data['status'] === 'skipped') {
                $inspection->skip_reason = $data['skip_reason'];
                $inspection->rescheduled_date = $data['rescheduled_date'];
                $equipment->next_inspection_date = $data['rescheduled_date'];
            } else {
                foreach (self::ITEMS as $key) {
                    if (!in_array($data['checklist'][$key] ?? null,['ok','problem','unchecked','na'],true)) {
                        throw ValidationException::withMessages(['checklist'=>'Заполните каждый пункт чек-листа.']);
                    }
                }
                if (($data['work_type'] ?? 'inspection') !== 'inspection' && !$inspection->approved_at) {
                    throw ValidationException::withMessages(['work_type'=>'Сначала представитель клуба или руководитель должен согласовать дополнительную работу.']);
                }
                $inspection->checklist = $data['checklist'];
                $inspection->work_type = $data['work_type'];
                $inspection->findings = $data['findings'] ?? null;
                $inspection->work_done = $data['work_done'];
                $inspection->verification = $data['verification'];
                $date = now($visit->club->timezone)->toDateString();
                $inspection->completed_date = $date;
                $equipment->last_inspection_date = $date;
                $equipment->next_inspection_date = InspectionCalendar::next($date);
            }
            $inspection->status = $data['status']; $inspection->save(); $equipment->save();
            return $inspection;
        });
    }
}
