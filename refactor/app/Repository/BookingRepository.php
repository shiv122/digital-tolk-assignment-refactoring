<?php

namespace DTApi\Repository;

use Event;
use Exception;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use DTApi\Mailers\AppMailer;
use DTApi\Models\Translator;
use Illuminate\Http\Request;
use DTApi\Events\SessionEnded;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCanceled;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use App\Jobs\Notification\NotificationJob;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    /*
     |=============================== Note After Refactoring ==================================|
     | 
     | 1. There were some spelling mistake that can lead to some frustrating bug down the line 
     | 2. We should not use acronyms for variable name. This can be confusing for someone without context
     | 3. There was some attempt of abstraction of logic but is was more confusing , consider creating helper classes for formatting and parsing 
     | 4. Casing should be universal. 
     |
     */
    public function getUsersJobs($userId)
    {
        // not sure why variable name was $cuser , either just use $user or write whatever "c" stand for, dont user acronym in variable names
        $user = User::findOrFail($userId);
        // not sure how we storing user type in db but if we use simple string as "customer" or "translator" we can about this 
        $userType  = $user->is('customer') ? 'customer' : ($user->is('translator') ? 'translator' : throw new Exception("Invalid user type"));

        $query = Job::query();

        $query->when($userType == 'customer', function ($q) use ($user) {
            $q
                ->where('user_id', $user->id)
                //not sure about data need but we already have user data so we can utilize "through" relationship to reduce unnecessary db lookups 
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc');
        });

        $query->when($userType == 'translator', function ($q) use ($user) {
            $q
                /**not sure how getTranslatorJobs this function is working as no implementation provided
                 * because in my opinion  $jobs->pluck('jobs')->all() this should not be required.
                 * 
                 * */
                ->getTranslatorJobs($user->id, 'new');
        });
        $jobs = $query->get();



        // assuming job->immediate is either "yes" or "no" we can use laravel collection method groupBy

        ["yes" => $emergencyJobs, "no" => $normalJobs] = $jobs->groupBy('immediate')->all();

        /**
         * Not sure about checkParticularJob but but pretty use this can be done more efficiently than Job::checkParticularJob($user_id, $item);
         * not sure if this is looking up some data in db or invoking it, 
         * 
         * Conclusion -> this can be done with some claver querying 
         */

        $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
            $item['usercheck'] = Job::checkParticularJob($user_id, $item);
        })->sortBy('due')->all();

        return response(compact(
            'emergencyJobs',
            'normalJobs',
            'user',
            'userType'
        ));
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory(int $userId, Request $request)
    {
        $pageNum = $request->get('page', 1);
        $emergencyJobs = [];
        //much prefer using  $request->user() instead of passing userId by argument (can be other user by mistake)
        $user = User::findOrFail($userId);

        $userType  = $user->is('customer') ? 'customer' : ($user->is('translator') ? 'translator' : throw new Exception("Invalid user type"));

        $jobs = Job::query();

        $jobs->when($userType == 'customer', function ($q) use ($user) {
            $q
                ->where("user_id", $user->id)
                //we can use newer array relation loading but now sure how we need it.
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc');
        })->when($userType == 'customer', function ($q) use ($user, $pageNum) {
            //again not sure why they are rolling there own hacky pagination in scope
            // but there's better way to do in but need the model code to implement it
            //this will throw circular reference for now need model code to fix it
            $q->getTranslatorJobsHistoric($user->id, 'historic', $pageNum);
        })->simplePaginate();

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $user, 'usertype' => $userType, 'numpages' => 0, 'pagenum' => 0];
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;
        if ($user->user_type != config('CUSTOMER_ROLE_ID')) {
            return response([
                'status' => 'fail',
                'message' => 'Translator can not create booking'
            ]);
        }

        $notImmediate = $data['immediate'] == 'no';
        $conditions = [
            [
                'condition' => !isset($data['from_language_id']),
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => 'from_language_id'
            ],
            [
                'condition' => $notImmediate && !empty($data['due_time']),
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => 'due_date',
            ],
            [
                'condition' => $notImmediate && !empty($data['due_time']),
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => 'due_time',
            ],
            [
                'condition' => $notImmediate && (!isset($data['customer_phone_type'], $data['customer_physical_type'])),
                'status' => 'fail',
                'message' => 'Du måste göra ett val här',
                'field_name' => 'customer_phone_type'
            ],
            [
                'condition' => $notImmediate && !empty($data['duration']),
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => 'duration',
            ]
        ];

        foreach ($conditions as $cond) {
            if ($cond['condition']) {
                $response['status'] = $cond['status'];
                $response['message'] = $cond['message'];
                $response['field_name'] = $cond['field_name'];
                return $response;
            }
        }

        if ($data['immediate'] == 'yes') {
            $due = Carbon::createFromFormat('Y-m-d H:i:s', $data['due_date'] . " " . $data['due_time']);
            if ($due->isPast()) {
                $response['status'] = 'fail';
                $response['message'] = "Can't create booking in past";
                return $response;
            }
            $response['type'] = 'regular';
            $data['due'] = $due;
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $response['customer_physical_type'] = $data['customer_physical_type'];

        if ($data['immediate'] == 'yes') {
            $data['due'] = Carbon::now()->addMinute($immediateTime)->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        }

        foreach ($data['job_for'] as $jobFor) {
            match ($jobFor) {
                'male' => $data['gender'] = 'male',
                'female' => $data['gender'] = 'female',
                'normal' => $data['certified'] = 'normal',
                'certified' => $data['certified'] = 'yes',
                'certified', 'normal' => $data['certified'] = 'both',
                'certified_in_law' => $data['certified'] = 'law',
                'certified_in_law', 'normal' => $data['certified'] = 'n_law',
                'certified_in_helth' => $data['certified'] = 'health',
                'certified_in_helth', 'normal' => $data['certified'] = 'n_health',
            };
        }

        $data['job_type'] = match ($consumerType) {
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid',
            default => null,
        };

        $data['b_created_at'] = date('Y-m-d H:i:s');

        isset($due) ? $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']) : "";

        $job = $user->jobs()->create($data);
        $response['status'] = 'success';
        $response['id'] = $job->id;
        $data['job_for'] = [];

        $data['job_for'][] = match ($job->gender) {
            'male' => 'Man',
            'female' => 'Kvinna',
            default => null,
        };

        $data['job_for'] = match ($job->certified) {
            'both' => ['normal', 'certified'],
            'yes' => 'certified',
            default => $job->certified,
        };
        //flatten the array
        $data['job_for'] = array_filter(array_merge(...$data['job_for']));

        $data['customer_town'] = $cuser->userMeta->city;
        $data['customer_type'] = $cuser->userMeta->customer_type;


        return response($response);
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $userType = $data['user_type'];
        // not sure why suppressing the error using @, this makes debugging a hellhole
        $job = Job::findOrFail(@$data['user_email_job_id']);
        // need relation type because we can just use $job->user if its one to one relationship
        $user = $job->user()->get()->first();
        $job->update([
            'user_email' => $data['user_email'],
            'reference' => trim($data['reference']) ?? "",
            'address' => trim($data['address']) ?? $user->userMeta->address,
            'instructions' => trim($data['instructions']) ?? $user->userMeta->instructions,
            'town' => trim($data['town']) ?? $user->userMeta->city,
        ]);

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $this->mailer->send(
            $email,
            $user->name,
            $subject,
            'emails.job-created',
            compact('user', 'job')
        );
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return response([
            'type' => $userType,
            'job' => $job,
            'status' => 'success',
        ]);
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        [$due_date, $due_time] = explode(" ", $job->due);
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];

        if ($job->gender != null) {
            $data['job_for'][] = match ($job->gender) {
                'male' => 'Man',
                'female' => 'Kvinna',
                default => '',
            };
        }

        if ($job->certified != null) {
            $data['job_for'][] = match ($job->certified) {
                'both' => ['Godkänd tolk', 'Auktoriserad'],
                'yes' => 'Auktoriserad',
                'n_health' => 'Sjukvårdstolk',
                'law', 'n_law' => 'Rätttstolk',
                default => $job->certified,
            };
        }

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $completedDate = date('Y-m-d H:i:s');
        $job = Job::with('translatorJobRel')->findOrFail($post_data["job_id"]);
        $interval = date_diff(
            date_create($job->due),
            date_create($completedDate)
        )->format('%h:%i:%s');

        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $interval;
        $job->save();

        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionTime = implode(' tim ', explode(':', $job->session_time)) . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send(
            $email,
            $name,
            $subject,
            'emails.session-ended',
            $data
        );

        $tr = $job->translatorJobRel->whereNull(["completed_at", 'cancel_at'])->first();

        if ($tr) {
            $tr->completed_at = $completedDate;
            $tr->completed_by = $post_data['userid'];
            $tr->save();

            $user = $tr->user;
            $email = $user->email;
            $name = $user->name;

            $data['for_text'] = 'lön';
            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
        }

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->firstOrFail();
        $job_type = match ($$userMeta->translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid'
        };
        // should be eager loaded
        $userLanguages = UserLanguages::where('user_id', $user_id)->get('lang_id')->pluck('lang_id')->all();
        // what does this returns ? why Job::find($v->id) in loop when we can preload the data
        $jobs = Job::getJobs(
            $user_id,
            $job_type,
            'pending',
            $userLanguages,
            $userMeta->gender,
            $userMeta->translator_level,
        );
        foreach ($jobs as $k => $job) {
            $jobuserid = $job->user_id;
            //load town with $job data, i am truly dont have words for why calling to db from loops
            //when if this doesnt invoke db which im not sure because i dont have implementation.
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($jobs[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($jobs->pluck('id'));
        return $jobs;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)
        /*
        |========================= Few Points ========================|
        | This code is wrong in so many levels , i can refactor it and make it so much better but i need more context
        |  
        | 1. Never call User::all()
        | 2. In 99 % case you should not invoke db data inside loop , this code is doing it multiple type 
        |    if user count is 10000 you just loaded all the data in memory and calling for db record 10000*(number of jobs)*   (assignedToPaticularTranslator + checkParticularJob + isNeedToDelayPush)
        | 3. If code is getting this nested consider different approach like : pipelines,queue & jobs  
         */
        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->firstOrFail();
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $phoneJobMessageTemplate = trans(
            'sms.phone_job',
            [
                'date' => $date,
                'time' => $time,
                'duration' => $duration,
                'jobId' =>  $job->id
            ]
        );
        $physicalJobMessageTemplate = trans(
            'sms.physical_job',
            [
                'date' => $date, 'time' => $time,
                'town' => $job->city ?? $jobPosterMeta->city,
                'duration' => $duration,
                'jobId' => $job->id
            ]
        );
        /*
        * There should be no comments like "This shouldn't be feasible, so no handling of this edge case"
        * we dont know all the edge cases hence the name
        */
        $message = match ("{$job->customer_physical_type}-{$job->customer_phone_type}") {
            'no-yes', 'yes-yes' => $phoneJobMessageTemplate,
            'yes-no' => $physicalJobMessageTemplate,
            default => throw new InvalidTypeException(),
        };

        foreach ($translators as $translator) {
            //prefer config() helper over env & this should be done by queue
            SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
        }
        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        $isNightTime = DateTimeHelper::isNightTime();
        if (!$isNightTime) return false;

        $userHasOptedOut = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($userHasOptedOut === 'yes') return true;

        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $dontNeedNotification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($dontNeedNotification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        //notification is a general function there should be a common job for is
        // i have made a job for notification with better name based api key method which i use
        // that uses Berkayk\OneSignal package also i can make my own implementation

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] === 'suitable_job') {
            $android_sound = ($data['immediate'] === 'no') ? 'normal_booking' : 'emergency_booking';
            $ios_sound = ($data['immediate'] === 'no') ? 'normal_booking.mp3' : 'emergency_booking.mp3';
        }

        dispatch(new NotificationJob(
            title: 'DigitalTolk',
            message: $msg_text,
            tags: json_decode($user_tags),
            data: $data,
            android_sound: $android_sound,
            ios_sound: $ios_sound,
            next_business_time: ($is_need_delay) ?  DateTimeHelper::getNextBusinessTimeString() : null
        ));
    }

    /**
     * @param Job $job
     * // why this labeled as mixed ??
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $job_type = $job->job_type;
        $translator_type = match ($job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
            default => throw new InvalidJobTypeException(),
        };
        $translator_level = match ($job->certified) {
            'yes', 'both' => ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'],
            'law', 'n_law' => ['Certified with specialisation in law'],
            'health', 'n_health' => ['Certified with specialisation in health care'],
            'normal' => ['Layman', 'Read Translation courses'],
            null => ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'],
            default => [],
        };

        $blocked_translator_ids = UsersBlacklist::where('user_id', $job->user_id)->get('translator_id')->pluck('translator_id')->all();

        $users = User::getPotentialUsers(
            $translator_type,
            $job->from_language_id,
            $$job->gender,
            $translator_level,
            $blocked_translator_ids
        );


        return $users;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        // i dont know the relation name otherwise this can be eager loaded 
        $current_translator = $job->translatorJobRel->whereNull('cancel_at')->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->whereNotNull('completed_at')->first();
        }
        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            $statusChanged = match ($old_status) {
                'timedout' => $this->changeTimedoutStatus($job, $data, $changedTranslator),
                'completed' => $this->changeCompletedStatus($job, $data),
                'started' => $this->changeStartedStatus($job, $data),
                'pending' => $this->changePendingStatus($job, $data, $changedTranslator),
                'withdrawafter24' => $this->changeWithdrawafter24Status($job, $data),
                'assigned' => $this->changeAssignedStatus($job, $data),
                default => false,
            };

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
        // return type is set to array but if statusChanged = false at the end then
        // there's not return from this function
        return [];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = now();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $this->jobToData($job), '*');
            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        if ($data['status'] === 'timedout' && empty($data['admin_comments'])) {
            return false;
        }
        if ($data['status'] === 'timedout') {
            $job->admin_comments = $data['admin_comments'];
        }
        $job->status = $data['status'];
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        if ($data['admin_comments'] === '') {
            return false;
        }
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] !== 'completed') {
            $job->save();
            return true;
        }

        if ($data['sesion_time'] === '') {
            return false;
        }

        $job->end_at = now();
        $job->session_time = $data['sesion_time'];
        $session_time = implode(' ', explode(':', $data['sesion_time'])) . ' min';

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        $user = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $email = $user->user->email;
        $name = $user->user->name;
        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $job->save();

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        }

        $subject = 'Avbokning av bokningsnr: #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        $job->save();
        return true;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . now()->format('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $msg_text_en = 'Detta är en påminnelse om att du har en ' . $language . 'tolkning';
        $msg_text_en .= $job->customer_physical_type == 'yes' ? ' (på plats i ' : ' (telefon) ';
        $msg_text_en .= $job->town . ') kl ' . explode(' ', $due)[1] . ' på ' . explode(' ', $due)[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!';

        $data = [
            'notification_type' => 'session_start_remind',
            'contents' => ['en' => $msg_text_en]
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (!in_array($data['status'], ['timedout']) || $data['admin_comments'] == '') {
            return false;
        }

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (!in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            return false;
        }
        if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
            return false;
        }

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];

        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
            $user = $job->user()->first();
            $email = $job->user_email ?? $user->email;
            $name = $user->name;
            $dataEmail = ['user' => $user, 'job' => $job];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

            $user = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
            $email = $user->user->email;
            $name = $user->user->name;
            $dataEmail = ['user' => $user, 'job' => $job];

            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
        }
        $job->save();
        return true;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;

        $data = [
            'user' => $user,
            'job'  => $job
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $data['user'] = $translator;
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        // assuming this will return bool
        $need_to_push = $this->isNeedToSendPush($user->id);
        if (!$need_to_push) {
            return;
        }
        $data = ['notification_type' => 'job_expired'];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.',
        ];
        $this->sendPushNotificationToSpecificUsers(
            [$user],
            $job->id,
            $data,
            $msg_text,
            $need_to_push,
        );
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->firstOrFail();

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type,
        ];

        [$data['due_date'], $data['due_time']] = explode(" ", $job->due);

        $data['job_for'] = match (true) {
            $job->gender === 'male' => ['Man'],
            $job->gender === 'female' => ['Kvinna'],
            $job->certified === 'both' => ['normal', 'certified'],
            $job->certified === 'yes' => ['certified'],
            default => [$job->certified],
        };
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        if (!$this->isNeedToSendPush($user->id)) {
            return;
        }
        $data['notification_type'] = 'session_start_remind';
        $msgTextPrefix = $job->customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen';
        $msg_text = ["en" => 'Du har nu fått ' . $msgTextPrefix . ' för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'];

        $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = '[' . implode(',{"operator": "OR"},', array_map(function ($user) {
            return '{"key": "email", "relation": "=", "value": "' . strtolower($user->email) . '"}';
        }, $users)) . ']';

        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {

        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            return [
                'status' => 'fail',
                'message' => "Du har redan en bokning den tiden $job->due. Du har inte fått denna tolkning"
            ];
        }
        if ($job->status != 'pending' && !Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            return [
                'status' => 'fail',
                'message' => "Du har redan en bokning den tiden $job->due. Du har inte fått denna tolkning"
            ];
        }

        // this section should be in transaction and should use queue system for sending mail

        $job->status = 'assigned';
        $job->save();

        // Get user details
        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;

        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

        $data = ['notification_type' => 'job_accepted'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = ["en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'];
        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }

        return [
            'status' => 'success',
            'list' => ['job' => $job],
            'message' => 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk ' . $job->duration . ' min ' . $job->due
        ];
    }

    public function cancelJobAjax($data, $user)
    {
        $user = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $isCustomer = $user->is('customer');
        $isWithin24Hours = ($job->due->diffInHours(now()) <= 24);

        if ($isCustomer) {
            $job->withdraw_at = now();
            $diffInHours = $job->withdraw_at->diffInHours($job->due);
            $job->status = ($diffInHours >= 24) ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();
            Event::fire(new JobWasCanceled($job));

            // there were no case for isWithin24Hours in old code, not sure about the constrains you guys using 
            if ($translator && $this->isNeedToSendPush($translator->id)) {
                $data = ['notification_type' => 'job_cancelled'];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];
                $this->sendPushNotificationToSpecificUsers([$translator], $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
            }
            return ['status' => 'success', 'jobstatus' => 'success'];
        }


        if (!$isWithin24Hours) {
            $customer = $job->user()->first();
            if ($customer && $this->isNeedToSendPush($customer->id)) {
                $data = ['notification_type' => 'job_cancelled'];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                ];
                $this->sendPushNotificationToSpecificUsers([$customer], $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
            }

            $job->status = 'pending';
            $job->created_at = now();
            $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
            $job->save();

            Job::deleteTranslatorJobRel($translator->id, $job_id);
            $this->sendNotificationTranslator($job, $this->jobToData($job), $translator->id);
            return ['status' => 'success'];
        }


        return  [
            'status' => 'fail',
            'message' => 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Ring på +46 73 75 86 865 för att avboka över telefon. Tack!'
        ];
    }




    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = match ($cuser_meta->translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };
        $user_language = UserLanguages::where('user_id', $cuser->id)->get('lang_id')->pluck('lang_id')->all();
        $job_ids = Job::getJobs(
            $cuser->id,
            $job_type,
            'pending',
            $user_language,
            $cuser_meta->gender,
            $cuser_meta->translator_level
        );
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            $cannotAcceptJob = $specific_job === 'SpecificJob' && $check_particular_job === 'userCanNotAcceptJob';
            $invalidPhysicalJob = (
                $job->customer_phone_type === 'no'
                || $job->customer_phone_type === ''
            )
                && $job->customer_physical_type === 'yes'
                && !$checktown;


            if ($cannotAcceptJob || $invalidPhysicalJob) {
                unset($job_ids[$k]);
            }
        }
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $job = Job::with('translatorJobRel')->findOrFail($post_data["job_id"]);
        if ($job->status != 'started') {
            return ['status' => 'success'];
        }
        $date = date('Y-m-d H:i:s');
        $interval = date_diff(
            date_create($job->due),
            date_create($date)
        )->format('%h:%i:%s');
        $job->end_at = $date;
        $job->status = 'completed';
        $job->session_time = $interval;
        // if this is one-to-one then we can just use $job->user
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
        $job->save();
        // dont use acronyms for variable names
        $tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $date;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        return ['status' => 'success'];
    }


    public function customerNotCall($post_data)
    {
        $job = Job::with('translatorJobRel')->findOrFail($post_data["job_id"]);
        $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->firstOrFail();
        $date = date('Y-m-d H:i:s');
        $job->update(['end_at' => $date, 'status' => 'not_carried_out_customer']);
        $translator->update(['completed_at' => $date, 'completed_by' => $translator->user_id]);
        return ['status' => 'success'];
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->user();
        $consumer_type = $cuser->consumer_type;
        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $query = Job::query();

            // i have not added all the condition but there should be a filter system class
            // that should implement all the filters , here's how i would do it 
            $filters = [
                'feedback' => function ($query) {
                    $query->where('ignore_feedback', 0)->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', 3);
                    });
                },
                'id' => function ($query, $value) {
                    $query->whereIn('id', (array) $value);
                },
                'lang' => function ($query, $value) {
                    $query->whereIn('from_language_id', (array) $value);
                },
                'status' => function ($query, $value) {
                    $query->whereIn('status', (array) $value);
                },
                'expired_at' => function ($query, $value) {
                    $query->where('expired_at', '>=', $value);
                },
                'will_expire_at' => function ($query, $value) {
                    $query->where('will_expire_at', '>=', $value);
                },
                'customer_email' => function ($query, $value) {
                    $userIds = DB::table('users')->whereIn('email', $value)->pluck('id');
                    $jobIds = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', $userIds)->pluck('job_id');
                    $query->whereIn('id', $jobIds);
                },
            ];

            foreach ($filters as $key => $callback) {
                if (isset($requestdata[$key]) && ($value = $requestdata[$key])) {
                    $callback($query, $value);
                }
            }
            // we should not send all the data always limit the data 
            $query->orderBy('created_at', 'desc');
            $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all') {
                return $query->get();
            }
            return $query->paginate(15);
        }

        $filters = [
            'id' => function ($query, $value) {
                $query->where('id', $value);
            },
            'consumer_type' => function ($query, $value) {
                $query->where('job_type', $value === 'RWS' ? 'rws' : 'unpaid');
            },
            'feedback' => function ($query, $value) {
                if ($value !== 'false') {
                    $query->where('ignore_feedback', 0)
                        ->whereHas('feedback', function ($q) {
                            $q->where('rating', '<=', 3);
                        });
                }
            },
            'lang' => function ($query, $value) {
                $query->whereIn('from_language_id', (array) $value);
            },
            'status' => function ($query, $value) {
                $query->whereIn('status', (array) $value);
            },
            'job_type' => function ($query, $value) {
                $query->whereIn('job_type', (array) $value);
            },
            'customer_email' => function ($query, $value) {
                $user = DB::table('users')->where('email', $value)->first();
                if ($user) {
                    $query->where('user_id', $user->id);
                }
            },
            'filter_timetype' => function ($query, $value) use ($requestdata) {
                if (in_array($value, ["created", "due"])) {
                    $dateColumn = $value === "created" ? 'created_at' : 'due';

                    if (isset($requestdata['from']) && $requestdata['from'] != "") {
                        $query->where($dateColumn, '>=', $requestdata["from"]);
                    }
                    if (isset($requestdata['to']) && $requestdata['to'] != "") {
                        $to = $requestdata["to"] . " 23:59:00";
                        $query->where($dateColumn, '<=', $to);
                    }
                    $query->orderBy($dateColumn, 'desc');
                }
            },
        ];

        $query = Job::query();

        foreach ($filters as $key => $filter) {
            if (isset($requestdata[$key])) {
                $filter($query, $requestdata[$key]);
            }
        }
        $query->orderBy('created_at', 'desc');
        $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all') {
            return $query->get();
        }
        return $query->paginate(15);
    }

    public function alerts()
    {
        $cuser = Auth::user();
        if (empty($cuser) && !$cuser->is('superadmin')) {
            throw new InvalidRequestException();
        }
        // cant we do it in older code we were trying to get ids where job->duration >= diffrence in sec (session_time)
        $query = Job::query()
            ->whereRaw("TIME_TO_SEC(session_time) / 60 >= duration * 2")
            ->where('jobs.ignore', 0);

        $languages = Language::where('active', '1')->orderBy('language')->get();
        // list has been depricated and again wher are we loading all the dataa
        // and why are we using DB instrad of model ??
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');
        $requestdata = Request::all();
        $filters = [
            'lang' => function ($query, $value) {
                $query->whereIn('jobs.from_language_id', $value);
            },
            'status' => function ($query, $value) {
                $query->whereIn('jobs.status', $value);
            },
            'job_type' => function ($query, $value) {
                $query->whereIn('jobs.job_type', $value);
            },
            'customer_email' => function ($query, $value) {
                $user = User::where('email', $value)->first();
                if ($user) {
                    $query->where('jobs.user_id', $user->id);
                }
            },
            'translator_email' => function ($query, $value) {
                $user = User::where('email', $value)->first();
                if ($user) {
                    $jobIds = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                    $query->whereIn('jobs.id', $jobIds);
                }
            },
            'filter_timetype' => function ($query, $value) use ($requestdata) {
                $filterColumn = $value === "created" ? 'jobs.created_at' : 'jobs.due';
                if (!empty($requestdata['from'])) {
                    $query->where($filterColumn, '>=', $requestdata["from"]);
                }
                if (!empty($requestdata['from'])) {
                    $to = $requestdata["to"] . " 23:59:59";
                    $query->where($filterColumn, '<=', $to);
                }
                $query->orderBy($filterColumn, 'desc');
            },
        ];

        foreach ($filters as $key => $filter) {
            if (isset($requestdata[$key])) {
                $filter($query, $requestdata[$key]);
            }
        }
        $allJobs =   $query
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->orderBy('jobs.created_at', 'desc')
            ->paginate(15);

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    // i will do the same steps as userLoginFailed
    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobId = $request['jobid'];

        $job = Job::findOrFail($jobId)->toArray();

        $data = [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
            'updated_at' => now(),
            'user_id' => $request['userid'],
            'job_id' => $jobId,
            'cancel_at' => now(),
        ];

        $dataReopen = [
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
        ];

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', $jobId)->update($dataReopen);
            $newJobId = $jobId;
        } else {
            $job = array_merge($job, [
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
                'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
                'admin_comments' => 'This booking is a reopening of booking #' . $jobId,
            ]);

            $newJob = Job::create($job);
            $newJobId = $newJob->id;
        }

        Translator::where('job_id', $jobId)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if (!isset($affectedRows)) {
            return ["Please try again!"];
        }

        $this->sendNotificationByAdminCancelJob($newJobId);
        return ["Tolk cancelled!"];
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }
}
