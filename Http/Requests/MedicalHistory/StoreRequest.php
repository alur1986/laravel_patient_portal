<?php

namespace App\Http\Requests\MedicalHistory;

use App\Http\Requests\Request;
use App\Model\MedicalHistory\Allergy;
use App\Model\MedicalHistory\CurrentMedication;
use App\Model\MedicalHistory\NewMedicalHistory;
use App\Model\MedicalHistory\SocialHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Session;
use Carbon\Carbon;

class StoreRequest extends Request
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'tobacco_use' => ['in:'.SocialHistory::YES.",".SocialHistory::NO.",".SocialHistory::NULL_VALUE],
//            'tobacco_use_week' => ['numeric', 'max:9999999'],
//            'tobacco_use_year' => ['numeric', 'max:9999999'],
            'alcohol_use' => ['in:'.SocialHistory::YES.",".SocialHistory::NO.",".SocialHistory::NULL_VALUE],
//            'alcohol_use_week' => ['numeric', 'max:9999999'],
            'drug_use' => ['in:'.SocialHistory::YES.",".SocialHistory::NO.",".SocialHistory::NULL_VALUE],
//            'drug_use_week' => ['numeric', 'max:9999999'],
            'exercise_use' => ['in:'.SocialHistory::YES.",".SocialHistory::NO.",".SocialHistory::NULL_VALUE],
//            'exercise_use_week' => ['numeric', 'max:9999999'],
            'weight' => ['in:'.SocialHistory::GAIN.",".SocialHistory::LESS.",".SocialHistory::NONE],
            'weight_info' => ['string', 'max:255'],
            'drug_use_period' => ['in:' . SocialHistory::DRUG_PERIOD_WEEK . ',' . SocialHistory::DRUG_PERIOD_MONTH],
            'drug_use_type' => ['sometimes', 'string'],

            'checkAlrg' => ['in:'.Allergy::YES],
            'drugs_allergies' => ['in:'.Allergy::YES.",".Allergy::NO],
            'drugAllergyId' => ['array'],
            'food_allergies' => ['in:'.Allergy::YES.",".Allergy::NO],
            'environment_allergies' => ['in:'.Allergy::YES.",".Allergy::NO],

            'drugMedication' => ['array'],
            'drugMedication.*' => ['string', 'max:255'],
            'drugReaction' => ['array'],
            'drugReaction.*' => ['string', 'max:255'],

            'foodMedication' => ['array'],
            'foodMedication.*' => ['string', 'max:255'],
            'foodReaction' => ['array'],
            'foodReaction.*' => ['string', 'max:255'],

            'environmentMedication' => ['array'],
            'environmentMedication.*' => ['string', 'max:255'],
            'environmentReaction' => ['array'],
            'environmentReaction.*' => ['string', 'max:255'],

            'checkCM' => ['in:'.NewMedicalHistory::YES],
            'prescription' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'prescriptionId' => ['array'],
            'vitamins' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'vitaminId' => ['array'],
            'overTheCounter' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'overTheCounterId' => ['array'],

            'prescriptionMedication' => ['array'],
            'prescriptionMedication.*' => ['string', 'max:255'],
            'prescriptionDose' => ['array'],
            'prescriptionDose.*' => ['string', 'max:255'],
            'prescriptionFrequency' => ['array'],
            'prescriptionFrequency.*' => ['string', 'max:255'],

            'vitaminMedication' => ['array'],
            'vitaminMedication.*' => ['string', 'max:255'],
            'vitaminDose' => ['array'],
            'vitaminDose.*' => ['string', 'max:255'],
            'vitaminFrequency' => ['array'],
            'vitaminFrequency.*' => ['string', 'max:255'],

            'overTheCounterMedication' => ['array'],
            'overTheCounterMedication.*' => ['string', 'max:255'],
            'overTheCounterDose' => ['array'],
            'overTheCounterDose.*' => ['string', 'max:255'],
            'overTheCounterFrequency' => ['array'],
            'overTheCounterFrequency.*' => ['string', 'max:255'],

            'checkFHH' => ['in:'.NewMedicalHistory::YES],
            'medicalIssueId' => ['array'],

            'diagnosis' => ['array'],
            'diagnosis.*' => ['string', 'max:255'],
            'relationship' => ['array'],
            'relationship.*' => ['string', 'max:255'],

            'checkCMH' => ['in:'.NewMedicalHistory::YES],
            'pregnant' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'breastfeeding' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'ongoing_conditions' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'conditionsId' => ['array'],

            'typeCMH' => ['array'],
            'typeCMH.*' => ['string', 'max:255'],
            'dateCMH' => ['array'],
//            'dateCMH.*' => ['date', 'date_format:m/d/Y'],
            'nameCMH' => ['array'],
            'nameCMH.*' => ['string', 'max:255'],

            'dietary' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'historyNutrition' => ['string', 'max:255'],

            'checkPMH' => ['in:'.NewMedicalHistory::YES],
            'surgeries' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'surgeriesId' => ['array'],
            'hospitalizations' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'hospitalizationsId' => ['array'],
            'implants' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'implantsId' => ['array'],
            'vaccines' => ['in:'.NewMedicalHistory::YES.",".NewMedicalHistory::NO],
            'vaccinesId' => ['array'],

            'typeSurgery' => ['array'],
            'typeSurgery.*' => ['string', 'max:255'],
            'dateSurgery' => ['array'],
//            'dateSurgery.*' => ['date', 'date_format:m/d/Y'],
            'physicianSurgery' => ['array'],
            'physicianSurgery.*' => ['string', 'max:255'],
            'citySurgery' => ['array'],
            'citySurgery.*' => ['string', 'max:255'],
            'stateSurgery' => ['array'],
            'stateSurgery.*' => ['string', 'max:255'],

            'reasonHospitalization' => ['array'],
            'reasonHospitalization.*' => ['string', 'max:255'],
            'dateHospitalization' => ['array'],
//            'dateHospitalization.*' => ['date', 'date_format:m/d/Y'],
            'hospitalHospitalization' => ['array'],
            'hospitalHospitalization.*' => ['string', 'max:255'],

            'typeImplant' => ['array'],
            'typeImplant.*' => ['string', 'max:255'],
            'dateImplant' => ['array'],
//            'dateImplant.*' => ['date', 'date_format:m/d/Y'],
            'placeImplant' => ['array'],
            'placeImplant.*' => ['string', 'max:255'],

            'dateVaccines' => ['array'],
//            'dateVaccines.*' => ['date', 'date_format:m/d/Y'],
            'nameVaccines' => ['array'],
            'nameVaccines.*' => ['string', 'max:255'],

            'new_medications' => 'sometimes|string',
        ];
    }

    public function getSocialHistoryValues(): array
    {
        $tobacco_use = (bool)$this->input('tobacco_use');
        if($this->input('tobacco_use')===SocialHistory::NULL_VALUE){
            $tobacco_use = null;
        }
        $tobacco_use_week = !$tobacco_use ? "" : $this->input('tobacco_use_week');
        $tobacco_use_year = !$tobacco_use ? "" : $this->input('tobacco_use_year');
        $alcohol_use = (bool)$this->input('alcohol_use');
        if($this->input('alcohol_use')===SocialHistory::NULL_VALUE){
            $alcohol_use = null;
        }
        $alcohol_use_week = !$alcohol_use ? "" : $this->input('alcohol_use_week');
        $drug_use = (bool)$this->input('drug_use');
        if($this->input('drug_use')===SocialHistory::NULL_VALUE){
            $drug_use = null;
        }
        $drug_use_week = !$drug_use ? "" : $this->input('drug_use_week');
        $drug_use_type = !$drug_use ? "" : $this->input('drug_use_type');
        $exercise_use = (bool)$this->input('exercise_use');
        if($this->input('exercise_use')===SocialHistory::NULL_VALUE){
            $exercise_use = null;
        }
        $exercise_use_week = !$exercise_use ? "" : $this->input('exercise_use_week');
        $weight = (int)$this->input('weight');
        $weight_info = !$weight ? "" : $this->input('weight_info');
        return [
            'tobacco_use' => $tobacco_use,
            'tobacco_use_week' => !is_null($tobacco_use) ? $tobacco_use_week : null,
            'tobacco_use_year' => !is_null($tobacco_use) ? $tobacco_use_year : null,
            'alcohol_use' => $alcohol_use,
            'alcohol_use_week' => !is_null($alcohol_use) ? $alcohol_use_week : null,
            'drug_use' => $drug_use,
            'drug_use_week' => !is_null($drug_use) ? $drug_use_week : 0,
            'drug_use_type' => !is_null($this->input('drug_use_type')) ? $drug_use_type : null,
            'drug_use_period' => !is_null($this->input('drug_use_period')) ? $this->input('drug_use_period') : SocialHistory::DRUG_PERIOD_WEEK,
            'exercise_use' => $exercise_use,
            'exercise_use_week' => !is_null($exercise_use) ? $exercise_use_week : null,
            'weight' => $weight,
            'weight_info' => $weight !== SocialHistory::NONE ? $weight_info : "",
        ];
    }

    public function getAllergiesValues(): array
    {
        $checkAlrg = (bool) $this->input('checkAlrg');
        return [
            'drugs' => !$checkAlrg ? (int) $this->input('drugs_allergies') : SocialHistory::NO,
            'foods' => !$checkAlrg ? (int) $this->input('food_allergies') : SocialHistory::NO,
            'environment' => !$checkAlrg ? (int) $this->input('environment_allergies') : SocialHistory::NO,
            'check' => $checkAlrg,
        ];
    }

    public function getMedicationValues(): array
    {
        $checkCM = (int) $this->input('checkCM');
        return [
            'prescription_medications' => !$checkCM ? (int) $this->input('prescription') : CurrentMedication::NO,
            'vitamins' => !$checkCM ? (int) $this->input('vitamins') : CurrentMedication::NO,
            'over_medications' => !$checkCM ? (int) $this->input('overTheCounter') : CurrentMedication::NO,
            'check' => !$checkCM ? CurrentMedication::NO : CurrentMedication::YES,
        ];
    }

    public function getFamilyHealthHistoryValues(): array
    {
        return [
            'check' => $this->input('checkFHH'),
        ];
    }

    public function getMedicalHistoryValues(): array
    {
        $checkMedicalHistory = !is_null($this->input('checkCMH')) ? (bool)$this->input('checkCMH') : null;
        return [
            'check' => $checkMedicalHistory,
            'pregnancy' => !$checkMedicalHistory ? $this->input('pregnant') : null,
            'breastfeeding' => !$checkMedicalHistory ? $this->input('breastfeeding') : null,
            'ongoing_conditions' => !$checkMedicalHistory ? $this->input('ongoing_conditions') : NewMedicalHistory::NO,
            'nutrition' => !$checkMedicalHistory ? $this->input('dietary') : NewMedicalHistory::NO,
            'nutrition_history' => (int)$this->input('dietary') === NewMedicalHistory::YES ? $this->input('considerations') : null
        ];
    }

    public function getPastMedicalHistoryValues(): array
    {
        $check = (bool)$this->input('checkPMH');
        return [
            'check' => $check,
            'surgeries' => !$check ? $this->input('surgeries') : NewMedicalHistory::NO,
            'hospitalizations' => !$check ? $this->input('hospitalizations') : NewMedicalHistory::NO,
            'implants' => !$check ? $this->input('implants') : NewMedicalHistory::NO,
            'vaccines' => !$check ? $this->input('vaccines') : NewMedicalHistory::NO,
        ];
    }

    public function getFormattedArray(int $id, string $id_name, array $inputAndColumnNames): array
    {
        $arrays = [];
        foreach ($inputAndColumnNames as $columnName => $inputName) {
            $arrays[$columnName] = $this->input($inputName);
        }
        $res = [];
        for ($i = 0; $i < count($arrays[array_keys($inputAndColumnNames)[0]]); $i++) {
            $res[$i][$id_name] = $id;
            foreach ($arrays as $columnName => $item) {
                if($columnName=='date'){
                    if(validateDate($item[$i], 'm/d/Y')) {
                        $item[$i] = Carbon::parse($item[$i])->format('Y-m-d H:s:i');
                    }elseif($item[$i]==='null'){
                        $item[$i] = null;
                    }else{
                        $item[$i] = convertDateOrTimestampToSystemTZ($item[$i]);
                    }
                }
                $res[$i][$columnName] = $item[$i];
            }
        }

        return $res;
    }

    public function getNewMedicationNames(): array
    {
        $newMedicationNames = explode(',', $this->input('new_medications'));
        return empty($newMedicationNames) ? array() : $newMedicationNames;
    }
}
