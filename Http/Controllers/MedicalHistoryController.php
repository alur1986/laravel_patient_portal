<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicalHistory\GetRequest;
use App\Http\Requests\MedicalHistory\StoreRequest;
use App\Http\Requests\Request;
use App\Jobs\GenerateMedicalHistoryPDF;
use App\Model\MedicalHistory\Allergy;
use App\Model\MedicalHistory\CurrentMedicalHistory;
use App\Model\MedicalHistory\CurrentMedication;
use App\Model\MedicalHistory\FamilyHealthHistory;
use App\Model\MedicalHistory\MedicationName;
use App\Model\MedicalHistory\NewMedicalHistory;
use App\Model\MedicalHistory\PastMedicalHistory;
use App\Model\MedicalHistory\SocialHistory;
use App\Services\RequestTypesService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Response;

class MedicalHistoryController extends Controller
{
    public function index(GetRequest $request)
    {
        $database_name = $request->getDatabaseName();
        $patientId = $request->getPatientId();

        $this->switchDatabase($database_name);

        return $request->getMedicalHistoryRes($patientId);
    }

    public function store(StoreRequest $request)
    {
        $database_name = $request->getDatabaseName();
        $patient_id = $request->getPatientId();

        $this->switchDatabase($database_name);

        $new_medical_history = DB::transaction(function () use ($patient_id, $request) {
            $new_medical_history = NewMedicalHistory::query()
                ->firstOrCreate(['patient_id' => $patient_id]);

            if ($new_medical_history->social_history()->exists()) {
                $social_history = SocialHistory::query()
                    ->where('id', '=', $new_medical_history->social_histories_id)
                    ->first();
                $social_history->update($request->getSocialHistoryValues());
            } else {
                $social_history = SocialHistory::create($request->getSocialHistoryValues());
            }

            if ($new_medical_history->allergy()->exists()) {
                $allergy = Allergy::query()
                    ->where('id', '=', $new_medical_history->allergies_id)
                    ->first();
                $allergy->update($request->getAllergiesValues());
            } else {
                $allergy = Allergy::create($request->getAllergiesValues());
            }
            $allergy->allergy_drug()->delete();
            $allergy->allergy_food()->delete();
            $allergy->allergy_environment()->delete();
            if (!$allergy->check) {
                if ((int)$allergy->drugs === Allergy::YES) {
                    $allergy->allergy_drug()->createMany($request->getFormattedArray($allergy->getKey(), 'allergies_id', [
                        'medication' => 'drugMedication',
                        'reaction' => 'drugReaction',
                    ]));
                }
                if ((int)$allergy->foods === Allergy::YES) {
                    $allergy->allergy_food()->createMany($request->getFormattedArray($allergy->getKey(), 'allergies_id', [
                        'food' => 'foodMedication',
                        'reaction' => 'foodReaction',
                    ]));
                }
                if ((int)$allergy->environment === Allergy::YES) {
                    $allergy->allergy_environment()->createMany($request->getFormattedArray($allergy->getKey(), 'allergies_id', [
                        'environment' => 'environmentMedication',
                        'reaction' => 'environmentReaction',
                    ]));
                }
            }

            if ($new_medical_history->current_medication()->exists()) {
                $current_medication = CurrentMedication::query()
                    ->where('id', '=', $new_medical_history->current_medications_id)
                    ->first();
                $current_medication->update($request->getMedicationValues());
            } else {
                $current_medication = CurrentMedication::create($request->getMedicationValues());
            }
            $current_medication->prescription_medication()->delete();
            $current_medication->vitamin()->delete();
            $current_medication->over_medication()->delete();
            if (!$current_medication->check) {
                if ((int)$current_medication->prescription_medications === CurrentMedication::YES) {
                    $current_medication->prescription_medication()->createMany($request->getFormattedArray($current_medication->getKey(), 'current_medications_id', [
                        'medication' => 'prescriptionMedication',
                        'dose' => 'prescriptionDose',
                        'frequency' => 'prescriptionFrequency',
                    ]));
                }
                if ((int)$current_medication->vitamins === CurrentMedication::YES) {
                    $current_medication->vitamin()->createMany($request->getFormattedArray($current_medication->getKey(), 'current_medications_id', [
                        'medication' => 'vitaminMedication',
                        'dose' => 'vitaminDose',
                        'frequency' => 'vitaminFrequency',
                    ]));
                }
                if ((int)$current_medication->over_medications === CurrentMedication::YES) {
                    $current_medication->over_medication()->createMany($request->getFormattedArray($current_medication->getKey(), 'current_medications_id', [
                        'medication' => 'overTheCounterMedication',
                        'dose' => 'overTheCounterDose',
                        'frequency' => 'overTheCounterFrequency',
                    ]));
                }
            }

            if ($new_medical_history->family_health_history()->exists()) {
                $family_health_history = FamilyHealthHistory::query()
                    ->where('id', '=', $new_medical_history->family_health_histories_id)
                    ->first();
                $family_health_history->update($request->getFamilyHealthHistoryValues());
            } else {
                $family_health_history = FamilyHealthHistory::create($request->getFamilyHealthHistoryValues());
            }
            $family_health_history->medical_issues()->delete();
            if (!$family_health_history->check) {
                $family_health_history->medical_issues()->createMany($request->getFormattedArray($family_health_history->getKey(), 'family_health_histories_id', [
                    'diagnosis' => 'diagnosis',
                    'relationship' => 'relationship',
                ]));
            }

            if ($new_medical_history->current_medical_history()->exists()) {
                $current_medical_history = CurrentMedicalHistory::query()
                    ->where('id', '=', $new_medical_history->current_medical_histories_id)
                    ->first();
                $current_medical_history->update($request->getMedicalHistoryValues());
            } else {
                $current_medical_history = CurrentMedicalHistory::create($request->getMedicalHistoryValues());
            }
            $current_medical_history->ongoing_condition()->delete();
            if (!$current_medical_history->check) {
                if ((int)$current_medical_history->ongoing_conditions === CurrentMedicalHistory::YES) {
                    $current_medical_history->ongoing_condition()->createMany($request->getFormattedArray($current_medical_history->getKey(), 'current_medical_histories_id', [
                        'type' => 'typeCMH',
                        'date' => 'dateCMH',
                        'name' => 'nameCMH',
                    ]));
                }
            }

            if ($new_medical_history->past_medical_history()->exists()) {
                $past_medical_history = PastMedicalHistory::query()
                    ->where('id', '=', $new_medical_history->past_medical_histories_id)
                    ->first();
                $past_medical_history->update($request->getPastMedicalHistoryValues());
            } else {
                $past_medical_history = PastMedicalHistory::create($request->getPastMedicalHistoryValues());
            }
            $past_medical_history->surgery()->delete();
            $past_medical_history->hospitalization()->delete();
            $past_medical_history->implant()->delete();
            $past_medical_history->vaccine()->delete();
            if (!$past_medical_history->check) {
                if ((int)$past_medical_history->surgeries === PastMedicalHistory::YES) {
                    $past_medical_history->surgery()->createMany($request->getFormattedArray($past_medical_history->getKey(), 'past_medical_histories_id', [
                        'type' => 'typeSurgery',
                        'date' => 'dateSurgery',
                        'physician' => 'physicianSurgery',
                        'city' => 'citySurgery',
                        'state' => 'stateSurgery',
                    ]));
                }
                if ((int)$past_medical_history->hospitalizations === PastMedicalHistory::YES) {
                    $past_medical_history->hospitalization()->createMany($request->getFormattedArray($past_medical_history->getKey(), 'past_medical_histories_id', [
                        'reason' => 'reasonHospitalization',
                        'date' => 'dateHospitalization',
                        'hospital' => 'hospitalHospitalization',
                    ]));
                }
                if ((int)$past_medical_history->implants === PastMedicalHistory::YES) {
                    $past_medical_history->implant()->createMany($request->getFormattedArray($past_medical_history->getKey(), 'past_medical_histories_id', [
                        'date' => 'dateImplant',
                        'type' => 'typeImplant',
                        'place' => 'placeImplant',
                    ]));
                }
                if ((int)$past_medical_history->vaccines === PastMedicalHistory::YES) {
                    $past_medical_history->vaccine()->createMany($request->getFormattedArray($past_medical_history->getKey(), 'past_medical_histories_id', [
                        'vaccine' => 'nameVaccines',
                        'date' => 'dateVaccines',
                    ]));
                }
            }

            $newMedicationNames = $request->getNewMedicationNames();
            if (!empty($newMedicationNames)) {
                foreach ($newMedicationNames as $medicationName) {
                    MedicationName::create([
                        'created_by' => $patient_id,
                        'name' => $medicationName,
                    ]);
                }
            }

            $new_medical_history->update([
                'social_histories_id' => $social_history->getKey(),
                'allergies_id' => $allergy->getKey(),
                'current_medications_id' => $current_medication->getKey(),
                'family_health_histories_id' => $family_health_history->getKey(),
                'current_medical_histories_id' => $current_medical_history->getKey(),
                'past_medical_histories_id' => $past_medical_history->getKey(),
            ]);

            return $new_medical_history;
        });

        if ($new_medical_history) {
            $accountId = $request->getAccountID();

            $job = (new GenerateMedicalHistoryPDF($accountId, $patient_id, $new_medical_history));
            $this->dispatchNow($job);
            return $this->sendResponse(Response::HTTP_OK, 'Medical history has been updated successfully', [], [], $request->__getRequestType());
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong', [], [], $request->__getRequestType());
    }

}
