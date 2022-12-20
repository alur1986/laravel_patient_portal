<?php


namespace App\Services;


use App\Appointment;
use App\ProvidersAdvanceSchedule;
use App\ServiceProvider;

class ProviderService
{
    public static function getProviderSchedule($provider_id, $clinic_id, $service_type, $service_duration, $outset = 30) {
        $outset_sec = $outset*60;
        $duration = intval($service_duration[0])*60;
        $clinic_timezone = AppointmentService::getClinicTZFromClinicId($clinic_id);

        $service_type = preg_replace("/[^A-Za-z0-9 ]/", '', $service_type[0]);

        $service_type_filter = ['both', $service_type];
        $available_days_day_times = ProvidersAdvanceSchedule::select('date_scheduled', 'from_time', 'to_time')
            ->where('clinic_id', $clinic_id)
            ->where('provider_id', $provider_id)
            ->where('date_scheduled', '>=', date('Y-m-d'))
            ->whereIn('available_for', $service_type_filter)
            ->get();

        $available_timestamp_ranges = [];
        foreach($available_days_day_times as $available_days_day_time){
            $str_from = $available_days_day_time['date_scheduled']." ".$available_days_day_time['from_time'];
            $str_to = $available_days_day_time['date_scheduled']." ".$available_days_day_time['to_time'];
            $available_timestamp_ranges[] = [strtotime($str_from), strtotime($str_to)];
        }

        $appointments_timestamp_ranges = [];
        $appointments = Appointment::with('clinic')->where('user_id', $provider_id)->where('status', 'booked')->get();
        foreach ($appointments as $key => $appointment) {
            $timestamp_duration = intval($appointment['duration']) * 60;
            $appointments_timestamp_ranges[] = [strtotime($appointment['appointment_datetime']), strtotime($appointment['appointment_datetime']) + $timestamp_duration];
        }

        $appointment_days_ids = [];
        foreach($available_timestamp_ranges as $key=>$available_timestamp_range){
            foreach($appointments_timestamp_ranges as $appointments_timestamp_range){
                if($appointments_timestamp_range[0]>=$available_timestamp_range[0] && $appointments_timestamp_range[1]<$available_timestamp_range[1]){
                    if(!in_array($key, $appointment_days_ids)){
                        $appointment_days_ids[] = $key;
                    }
                    $available_timestamp_ranges[$key][]=$appointments_timestamp_range[0];
                    $available_timestamp_ranges[$key][]=$appointments_timestamp_range[1];
                }
            }
        }

        foreach($appointment_days_ids as $appointment_days_id){
            $ranges = [];
            $key = $appointment_days_id;
            $appointment_day = $available_timestamp_ranges[$key];
            sort($appointment_day);

            for($i=0; $i<=count($appointment_day)-2; $i=$i+2){
                $ranges[] = [$appointment_day[$i], $appointment_day[$i+1]];
            }
            $available_timestamp_ranges[$key]= $ranges;
        }

        foreach($available_timestamp_ranges as $key=>$available_timestamp_range){

            if(gettype($available_timestamp_range[0])=='integer'){
                $available_timestamp_ranges[$key][0] = [$available_timestamp_range[0], $available_timestamp_range[1]];
                unset($available_timestamp_ranges[$key][1]);
            }

        }

        $final_data = [];
        $final_data['days'] = [];

        foreach($available_timestamp_ranges as $key=>$available_timestamp_range){
            $datetime = [];
            $has_available_period = 0;
            foreach($available_timestamp_range as $keya=>$range){
                $todayInClinicTZ = time();
                $from = $range[0];
                $to = $range[1];

                if ($from < $todayInClinicTZ) {
                    $from = ceil($todayInClinicTZ /60 / $outset)*60 * $outset;
                }

                unset($available_timestamp_ranges[$key][$keya][0]);
                unset($available_timestamp_ranges[$key][$keya][1]);

                if($from+$duration<=$to){

                    $from_readable_date = date('d/m/Y', $from);
                    $available_timestamp_ranges[$key]['day']=$from_readable_date;

                    $round = ceil($from /60 / $outset)*60 * $outset;

                    $from_readable_time = date('H:i', $round);
                    $date_timestamp = strtotime(str_replace("/", "-", $from_readable_date));

                    $datetime['date'] = $date_timestamp;
                    $datetime['clinic_timezone'] = $clinic_timezone;
                    $datetime['hours'][]=$from_readable_time;

                    while($from+$duration<=$to){
                        $from = $from + $outset_sec;

                        $round = ceil($from/60/$outset)*60 * $outset;
                        if($round+$duration<=$to) {
                            $has_available_period = 1;
                            $from_readable_time = date('H:i', $round);

                            $available_timestamp_ranges[$key]['hours'][] = $from_readable_time;
                            $datetime['hours'][] = $from_readable_time;
                        }
                    }
                }
            }
            if($has_available_period==1){
                $final_data['days'][] = $datetime;
            }
        }

        usort($final_data['days'], function($a, $b) {
            $a = $a['date'];
            $b = $b['date'];
            return $a < $b ? -1 : 1;
        });

        return $final_data;
    }

    public static function getFirstTimestamp($provider_id, $clinic_id, $service_type, $service_duration, $outset = 30) {
        $duration = intval($service_duration)*60;
        $outset_sec = $outset*60;

        $service_type = preg_replace("/[^A-Za-z0-9 ]/", '', $service_type[0]);

        $service_type_filter = ['both', $service_type];
        $available_days_day_times = ProvidersAdvanceSchedule::select('date_scheduled', 'from_time', 'to_time')
            ->where('clinic_id', $clinic_id)
            ->where('provider_id', $provider_id)
            ->where('date_scheduled', '>=', date('Y-m-d'))
            ->whereIn('available_for', $service_type_filter)
            ->get();

        $available_timestamp_ranges = [];
        foreach($available_days_day_times as $available_days_day_time){
            $str_from = $available_days_day_time['date_scheduled']." ".$available_days_day_time['from_time'];
            $str_to = $available_days_day_time['date_scheduled']." ".$available_days_day_time['to_time'];
            $available_timestamp_ranges[] = [strtotime($str_from), strtotime($str_to)];
        }

        $appointments_timestamp_ranges = [];
        $appointments = Appointment::with('clinic')->where('user_id', $provider_id)->where('status', 'booked')->get();
        foreach ($appointments as $key => $appointment) {
            $timestamp_duration = intval($appointment['duration']) * 60;
            $appointments_timestamp_ranges[] = [strtotime($appointment['appointment_datetime']), strtotime($appointment['appointment_datetime']) + $timestamp_duration];
        }

        $appointment_days_ids = [];
        foreach($available_timestamp_ranges as $key=>$available_timestamp_range){
            foreach($appointments_timestamp_ranges as $appointments_timestamp_range){
                if($appointments_timestamp_range[0]>$available_timestamp_range[0] && $appointments_timestamp_range[1]<$available_timestamp_range[1]){
                    if(!in_array($key, $appointment_days_ids)){
                        $appointment_days_ids[] = $key;
                    }
                    $available_timestamp_ranges[$key][]=$appointments_timestamp_range[0];
                    $available_timestamp_ranges[$key][]=$appointments_timestamp_range[1];
                }
            }
        }

        foreach($appointment_days_ids as $appointment_days_id){
            $ranges = [];
            $key = $appointment_days_id;
            $appointment_day = $available_timestamp_ranges[$key];
            sort($appointment_day);

            for($i=0; $i<=count($appointment_day)-2; $i=$i+2){
                $ranges[] = [$appointment_day[$i], $appointment_day[$i+1]];
            }
            $available_timestamp_ranges[$key]= $ranges;
        }

        foreach($available_timestamp_ranges as $key=>$available_timestamp_range){
            if(gettype($available_timestamp_range[0])=='integer'){
                $available_timestamp_ranges[$key][0] = [$available_timestamp_range[0], $available_timestamp_range[1]];
                unset($available_timestamp_ranges[$key][1]);
            }
        }

        usort($available_timestamp_ranges, function($a, $b) {
            return ($a[0][0] < $b[0][0]) ? -1 : 1;
        });

        foreach($available_timestamp_ranges as $key=>$available_timestamp_range){
            $datetime = [];
            $has_available_period = 0;
            foreach($available_timestamp_range as $keya=>$range){
                $current_time = time();
                $from = $range[0];
                $to = $range[1];

                if ($from < $current_time) {
                    $from = ceil($current_time /60 / $outset)*60 * $outset;
                }

                if($from+$duration<=$to){
                    $round = ceil($from /60 / $outset)*60 * $outset;
                    if($round+$duration<=$to) {
                        $has_available_period++;
                        if($has_available_period==1){
                            $datetime['first_timestamp']=$round;
                            $from_readable_time = date('H:i', $round);
                            $datetime['next_timestamps'][]=$from_readable_time;
                        }else{
                            $from_readable_time = date('H:i', $round);
                            $datetime['next_timestamps'][]=$from_readable_time;
                        }

                        while($from+$duration<=$to){
                            $from = $from + $outset_sec;

                            $round = ceil($from /60 / $outset)*60 * $outset;
                            if($round+$duration<=$to) {
                                $has_available_period = 1;
                                $from_readable_time = date('H:i', $round);
                                $datetime['next_timestamps'][] = $from_readable_time;
                            }
                        }
                    }
                }
            }
            return $datetime;
        }
        return "";
    }

    public static function getFirstAvailable($clinic_id, $service_id, $service_type, $service_duration, $outset = 30) {
        #connect account db
        $clinic_timezone = AppointmentService::getClinicTZFromClinicId($clinic_id);
        $service_type = preg_replace("/[^A-Za-z0-9 ]/", '', $service_type[0]);

        $provider_ids = ServiceProvider::where('service_id', $service_id)->pluck('user_id');

        $providers_first_timestamps = [];
        $providers_next_datetimes = [];

        foreach($provider_ids as $key=>$provider_id) {
            $provider_data = self::getFirstTimestamp($provider_id, $clinic_id, $service_type, $service_duration, $outset);
            if(!$provider_data){
                $provider_timestamp = "";
            }else{$providers_first_timestamps[$provider_id] = $provider_data['first_timestamp'];
                $providers_next_datetimes[$provider_id] = $provider_data['next_timestamps'];}
        }

        asort($providers_first_timestamps);
        foreach ($providers_first_timestamps as $provider_id => $provider_timestamp) {
            break;
        }

        if($provider_timestamp==""){
            return "providers_unavailable";
        }

        $provider_next_datetimes = $providers_next_datetimes[$provider_id];

        $readable_date = date('d/m/Y', $provider_timestamp);
        $readable_hour = date('H', $provider_timestamp);

        $data = [];

        $timestamp_date = strtotime(str_replace("/", "-", $readable_date));

        $data['provider_id'] = strval($provider_id);
        $data['clinic_timezone'] = $clinic_timezone;
        $data['first_day'] = $timestamp_date;
        $data['first_hour'] = $readable_hour;

        $data['days'] = [];

        $data['days']['day'] = $timestamp_date;
        $data['days']['hours'] = $provider_next_datetimes;

        return $data;
    }
}
