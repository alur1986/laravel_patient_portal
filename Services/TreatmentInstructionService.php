<?php


namespace App\Services;

use App\ServicePostTreatmentInstruction;
use App\ServiceTreatmentInstruction;
use App\PatientAccount;

class TreatmentInstructionService
{
    const POST_INSTRUCTION_TYPE = 'Post';
    const PRE_INSTRUCTION_TYPE = 'Pre';

    public static function getPostTreatmentInstructionsByUser($user_id, $database_name, $account_id): array
    {
        return self::getTreatmentInstructionsByUser(self::POST_INSTRUCTION_TYPE, $user_id, $database_name, $account_id);
    }

    public static function getPreTreatmentInstructionsByUser($user_id, $database_name, $account_id): array
    {
        return self::getTreatmentInstructionsByUser(self::PRE_INSTRUCTION_TYPE, $user_id, $database_name, $account_id);
    }

    private static function getTreatmentInstructionsByUser($instructionType, $user_id, $database_name, $account_id): array
    {
        switchDatabase($database_name);

        $treatment_instructions = [];
        if ($instructionType == self::PRE_INSTRUCTION_TYPE) {
            $appointments_services = self::getAppointmentsServicesByUser($user_id, $account_id, "upcoming");
            $appointments_services_ids = array_unique(array_column($appointments_services, 'service_id'));
            $treatment_instructions = self::getPreTreatmentInstructions($user_id, $appointments_services_ids);
        } elseif ($instructionType == self::POST_INSTRUCTION_TYPE) {
            $appointments_services = self::getAppointmentsServicesByUser($user_id, $account_id, "past");
            $appointments_services_ids = array_unique(array_column($appointments_services, 'service_id'));
            $treatment_instructions = self::getPostTreatmentInstructions($user_id, $appointments_services_ids);
        }

        switchDatabase($database_name);

        $data = [];

        foreach($appointments_services as $appointments_service){

            $treatment_instructions_ids = array_keys(array_column($treatment_instructions, 'service_id'), $appointments_service['service_id']);
            $keys = array_flip($treatment_instructions_ids);
            $appointment_treatment_instructions = array_intersect_key($treatment_instructions,$keys);

            foreach ($appointment_treatment_instructions as $appointment_treatment_instruction) {
                $created_date = strtotime($appointments_service['appointment_datetime']);
                $formatted_treatment = [
                    'id' => (string)$appointment_treatment_instruction['id'],
                    'title' => $appointment_treatment_instruction['title'],
                    'description' => $appointment_treatment_instruction['description'],
                    'date' => $created_date
                ];

                $data[] = $formatted_treatment;
            }
        }

        if ($instructionType == self::POST_INSTRUCTION_TYPE) {
            usort($data , function($a, $b) {
                return $a['date'] < $b['date'] ? 1 : -1;
            });
        }

        return array_values($data);
    }

    private static function getAppointmentsServicesByUser($user_id, $account_id, $period = "past")
    {
        $patient_accounts = PatientAccount::where('patient_user_id', $user_id)->where('account_id', $account_id)->select('patient_id')->first();
        $patient_ids = array_values($patient_accounts->toArray());

        if($period == 'past'){
            $appointments = AppointmentService::getAppointmentsByPeriod($patient_ids[0], [], 'past');
            return $appointments;
        }
            $appointments = AppointmentService::getAppointmentsByPeriod($patient_ids[0], [], 'upcoming');
            return $appointments;
    }

    private static function getPreTreatmentInstructions($user_id, $appointments_services_ids)
    {
        $services_pre_treatment_instruction = ServiceTreatmentInstruction::with(['pre_treatment_instructions' => function ($q) use ($user_id) {
            $q->select('pre_treatment_instructions.id', 'pre_treatment_instructions.title', 'pre_treatment_instructions.description');
        }])
            ->whereIn('service_treatment_instructions.service_id', array_values($appointments_services_ids))
            ->get();

        $treatment_instructions = [];
        foreach ($services_pre_treatment_instruction as $service_pre_treatment_instruction) {
            $pre_treatment_instructions = $service_pre_treatment_instruction->pre_treatment_instructions;
            $pre_treatment_instructions['service_id'] = $service_pre_treatment_instruction['service_id'];
            $treatment_instructions[] = $pre_treatment_instructions;
        }

        return $treatment_instructions;
    }

    private static function getPostTreatmentInstructions($user_id, $appointments_services_ids)
    {
        $service_post_treatment_instruction = ServicePostTreatmentInstruction::with(['post_treatment_instructions' => function ($q) use ($user_id) {
            $q->select('post_treatment_instructions.id', 'post_treatment_instructions.title', 'post_treatment_instructions.description');
        }])
            ->whereIn('service_post_treatment_instructions.service_id', array_values($appointments_services_ids))
            ->get();

        $treatment_instructions = [];
        foreach ($service_post_treatment_instruction as $service_post_treatment_instruction) {
            $treatment_instruction = $service_post_treatment_instruction->post_treatment_instructions;
            $treatment_instruction['service_id'] = $service_post_treatment_instruction['service_id'];
            $treatment_instructions[] = $treatment_instruction;
        }

        return $treatment_instructions;
    }
}
