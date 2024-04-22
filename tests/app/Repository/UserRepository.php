<?php

namespace DTApi\Repository;

use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Type;
use DTApi\Models\UsersBlacklist;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use DTApi\Models\User;
use DTApi\Models\Town;
use DTApi\Models\UserMeta;
use DTApi\Models\UserTowns;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\FirePHPHandler;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class UserRepository extends BaseRepository
{

    protected $model;
    protected $logger;

    /**
     * @param User $model
     */
    function __construct(User $model)
    {
        parent::__construct($model);
        //        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function createOrUpdate($id = null, $request)
    {
        $model = is_null($id) ? new User : User::findOrFail($id);

        $fields = [
            'user_type' => $request['role'],
            'name' => $request['name'],
            'company_id' =>  $request['company_id'] ?? 0,
            'department_id' =>  $request['department_id'] ?? 0,
            'email' => $request['email'],
            'dob_or_orgid' => $request['dob_or_orgid'],
            'phone' => $request['phone'],
            'mobile' => $request['mobile'],
        ];

        if (is_null($id) || ($id && $request->filled('password'))) {
            $fields['password'] = bcrypt($request['password']);
        }

        $model->fill($fields);
        $model->detachAllRoles();
        $model->save();

        if ($request['role'] == env('CUSTOMER_ROLE_ID')) {

            if ($request['consumer_type'] == 'paid' && empty($request['company_id'])) {
                $type = Type::where('code', 'paid')->first();
                $company = Company::create([
                    'name' => $request['name'],
                    'type_id' => $type->id,
                    'additional_info' => 'Created automatically for user ' . $model->id
                ]);
                $department = Department::create([
                    'name' => $request['name'],
                    'company_id' => $company->id,
                    'additional_info' => 'Created automatically for user ' . $model->id
                ]);

                $model->company_id = $company->id;
                $model->department_id = $department->id;
                $model->save();
            }

            $userMetaFields = [
                'consumer_type',
                'customer_type',
                'username',
                'post_code',
                'address',
                'city',
                'town',
                'country',
                'reference' => isset($request['reference']) && $request['reference'] == 'yes' ? '1' : '0',
                'additional_info',
                'cost_place',
                'fee',
                'time_to_charge',
                'time_to_pay',
                'charge_ob',
                'customer_id',
                'charge_km',
                'maximum_km',
            ];
            // not sure why we are creating and then updating but i left it 
            // thinking there are some observers that need this kind of behavior
            $userMeta = UserMeta::firstOrCreate(['user_id' => $model->id]);
            $userMeta->fill($request->only($userMetaFields))->save();

            $userBlacklist = UsersBlacklist::where('user_id', $model->id)->pluck('translator_id')->all();
            /*
            why are we first creating  blacklist and then deleting it??
                                    $already_exist = UsersBlacklist::translatorExist($model->id, $translatorId);
                                    if ($already_exist == 0) {
                                        $blacklist->user_id = $model->id;
                                        $blacklist->translator_id = $translatorId;
                                        $blacklist->save();
                                    }
                                    $blacklistUpdated [] = $translatorId;
            and then 
                            if ($blacklistUpdated) {
                                UsersBlacklist::deleteFromBlacklist($model->id, $blacklistUpdated);
                            }
            */
            $intersect = array_intersect($userBlacklist, $request->input('translator_ex', []));
            if ($request['translator_ex'] || !empty($intersect)) {
                $toCreate = array_diff($request['translator_ex'], $intersect);
                UsersBlacklist::where('user_id', $model->id)->whereIn('translator_id', $toCreate)->delete();
                foreach ($toCreate as $translatorId) {
                    UsersBlacklist::firstOrCreate(['user_id' => $model->id, 'translator_id' => $translatorId]);
                }
            }
        } elseif ($request['role'] == env('TRANSLATOR_ROLE_ID')) {
            $userMetaAttributes = [
                'translator_type',
                'worked_for',
                'organization_number',
                'gender',
                'translator_level',
                'additional_info',
                'post_code',
                'address',
                'address_2',
                'town'
            ];

            $userMetaData = array_only($request->all(), $userMetaAttributes);
            $userMetaData['organization_number'] = $request['worked_for'] === 'yes' ? $request['organization_number'] : null;

            $userMeta = UserMeta::updateOrCreate(['user_id' => $model->id], $userMetaData);




            $data = array_intersect_key($request->all(), array_flip($userMetaAttributes));
            $data['organization_number'] = $request['worked_for'] === 'yes' ? $request['organization_number'] : null;

            if ($request->has('user_language')) {
                $userLanguages = $request['user_language'];
                $existingLangIds = UserLanguages::where('user_id', $model->id)->pluck('lang_id')->toArray();

                $newLangIds = array_diff($userLanguages, $existingLangIds);
                $deletedLangIds = array_diff($existingLangIds, $userLanguages);

                foreach ($newLangIds as $langId) {
                    UserLanguages::create([
                        'user_id' => $model->id,
                        'lang_id' => $langId
                    ]);
                }

                if ($deletedLangIds) {
                    UserLanguages::where('user_id', $model->id)->whereIn('lang_id', $deletedLangIds)->delete();
                }
            }
        }

        if ($request->has('new_towns')) {
            Town::create(['townname' => $request['new_towns']]);
        }

        $townidUpdated = [];
        if ($request->has('user_towns_projects')) {
            UserTowns::where('user_id', $model->id)->delete();
            foreach ($request['user_towns_projects'] as $townId) {
                if (UserTowns::townExist($model->id, $townId) === 0) {
                    UserTowns::create(['user_id' => $model->id, 'town_id' => $townId]);
                }
                $townidUpdated[] = $townId;
            }
        }

        $status = $request->input('status', '0');
        $action = match ([$status, $model->status]) {
            ['1', '0'] => fn () => $this->enable($model->id),
            ['0', '1'] => fn () => $this->disable($model->id),
            default => fn () => null,
        };

        $action();

        return $model ?? false;
    }

    public function enable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '1';
        $user->save();
    }

    public function disable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '0';
        $user->save();
    }

    public function getTranslators()
    {
        return User::where('user_type', 2)->get();
    }
}
