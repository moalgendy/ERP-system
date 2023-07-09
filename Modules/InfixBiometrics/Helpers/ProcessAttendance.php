<?php

namespace Modules\InfixBiometrics\Helpers;

use App\Models\StudentRecord;
use App\Scopes\AcademicSchoolScope;
use App\SmAcademicYear;
use App\SmStaffAttendence;
use App\SmStudentAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\InfixBiometrics\Entities\InfixBioSetting;

class ProcessAttendance
{
    private $school_id;
    private $setting;
    private $academic_id;
    private $user;
    private $device_log;

    public function __construct($device_log, $user)
    {
        $this->school_id = $device_log->school_id;
        $this->device_log = $device_log;
        $this->user = $user;
        $this->academic_id = SmAcademicYear::API_ACADEMIC_YEAR($device_log->school_id);
        $this->setting = bioAttendanceSettings($this->school_id);
    }


    public function process()
    {

        if ($this->user->role_id == 2) {
            $this->studentBioAttendance();
        } else {
            $this->staffBioAttendance();
        }

    }


    function staffBioAttendance()
    {
        $school_id = $this->school_id;
        $attendance_setting = $this->setting;
        $sm_staff = $this->user->staff;
        if (!$sm_staff) {
            return;
        }

//        DB::table('device_log')->where('userid', $device_log->userid)->update(array('role_id' => $sm_staff->role_id, 'profile_id' => $sm_staff->id));

        $attendance = SmStaffAttendence::where('staff_id', $sm_staff->id)->where('attendence_date', date('Y-m-d', strtotime($this->device_log->checktime)))->first();

        if ($attendance) {
            $attendance->exit_time = $this->device_log->checktime;
            $attendance->save();
        } else {
            $attendance = new SmStaffAttendence();
            $attendance->come_from = 'device';
            $attendance->staff_id = $sm_staff->id;
            $attendance->attendence_type = $this->attendanceType($this->device_log->checktime);
            $attendance->attendence_date = date('Y-m-d', strtotime($this->device_log->checktime));
            $attendance->notes = 'Biometric Staff Attendance';
            $attendance->academic_id = $this->academic_id;
            $attendance->school_id = $school_id;
            $attendance->save();

            $compact['slug'] = 'staff';
            $compact['user_email'] = $sm_staff->email;
            $compact['staff_name'] = $sm_staff->full_name;
            $compact['attendance_date'] = date('Y-m-d h:i:s a', strtotime($this->device_log->checktime));

            if ($attendance_setting->staff_sms) {
                if ($attendance->attendence_type == "P") {
                    @send_sms($sm_staff->mobile, 'staff_attendance', $compact);
                } elseif ($attendance->attendence_type == "A") {
                    @send_sms($sm_staff->mobile, 'staff_absent', $compact);
                } elseif ($attendance->attendence_type == "L") {
                    @send_sms($sm_staff->mobile, 'staff_late', $compact);
                }
            }
        }
    }


    public function studentBioAttendance()
    {
        $school_id = $this->school_id;
        $sm_students = $this->user->student;
        if (!$sm_students) {
            return;
        }

        $record = StudentRecord::where('student_id', $sm_students->id)->where('school_id', $school_id)->first();

        if (!$record) {
            return;
        }

        $attendance_setting = $this->setting;


        $attendance = SmStudentAttendance::withOutGlobalScope(AcademicSchoolScope::class)->where('student_id', $sm_students->id)->where('school_id', $school_id)->whereDate('attendance_date', date('Y-m-d', strtotime($this->device_log->checktime)))->where('academic_id', $this->academic_id)->where('record_id', $record->id)->first();

        $compact['attendance_date'] = date('Y-m-d h:i:s a', strtotime($this->device_log->checktime));
        $compact['user_email'] = $sm_students->email;
        $compact['student_name'] = $sm_students->full_name;

        if ($attendance) {
            if ($this->attendanceType($this->device_log->checktime, true) == 'E' && !$attendance->exit_time) {
                $attendance->exit_time = $this->device_log->checktime;
                $attendance->save();

                if ($attendance_setting->parent_sms && $sm_students->parents) {
                    $compact['user_email'] = $sm_students->parents->guardians_email;
                    $compact['parent_name'] = $sm_students->parents->guardians_name;
                    @send_sms($sm_students->parents->guardians_mobile, 'student_checkout', $compact);
                }
            }

            return;
        }
        $attendance = new SmStudentAttendance();
        $attendance->student_id = $sm_students->id;
        $attendance->come_from = 'device';
        $attendance->record_id = $record->id;
        $attendance->student_record_id = $record->id;
        $attendance->school_id = $school_id;
        if (moduleStatusCheck('University')) {
            $attendance->un_session_id = $record->un_session_id;
            $attendance->un_faculty_id = $record->un_faculty_id;
            $attendance->un_department_id = $record->un_department_id;
            $attendance->un_academic_id = $record->un_academic_id;
            $attendance->un_semester_id = $record->un_semester_id;
            $attendance->un_section_id = $record->un_section_id;
        } else {
            $attendance->class_id = $record->class_id;
            $attendance->section_id = $record->section_id;
            $attendance->academic_id = $this->academic_id;
        }

        $attendance->attendance_type = $this->attendanceType($this->device_log->checktime);
        $attendance->attendance_date = date('Y-m-d', strtotime($this->device_log->checktime));
        $attendance->notes = 'Biometric Student Attendance';
        $attendance->save();

        if ($attendance_setting->student_sms) {
            if ($attendance->attendance_type == "P") {
                @send_sms($sm_students->mobile, 'student_attendance', $compact);
            } elseif ($attendance->attendance_type == "L") {
                @send_sms($sm_students->mobile, 'student_late', $compact);
            }
        }

        if ($attendance_setting->parent_sms && $sm_students->parents) {
            $compact['user_email'] = $sm_students->parents->guardians_email;
            $compact['parent_name'] = $sm_students->parents->guardians_name;
            if ($attendance->attendance_type == "P") {
                @send_sms($sm_students->parents->guardians_mobile, 'student_attendance_for_parent', $compact);
            } elseif ($attendance->attendance_type == "L") {
                @send_sms($sm_students->parents->guardians_mobile, 'student_late_for_parent', $compact);
            }
        }
    }


    public function attendanceType($checkTime, $check_exit = false): string
    {
        $start_time = Carbon::parse(date('h:i A', strtotime($this->setting->start_time)));
        $checkTime = Carbon::parse(date('h:i A', strtotime($checkTime)));
        $consider_start_time = Carbon::parse(date('h:i A', strtotime($this->setting->consider_start_time)));
        $exit_time = Carbon::parse(date('h:i A', strtotime($this->setting->exit_time)));

        if ($checkTime->lessThanOrEqualTo($start_time)  || ($checkTime->lessThanOrEqualTo($consider_start_time))) {
            return 'P';
        }

        if ($checkTime->lessThan($exit_time)) {
            return 'L';
        }

        if ($check_exit) {
            return 'E';
        }

        return 'A';
    }

}