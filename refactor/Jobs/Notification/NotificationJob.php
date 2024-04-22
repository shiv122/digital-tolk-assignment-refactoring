<?php

namespace App\Jobs\Notification;

use Exception;
use Illuminate\Bus\Queueable;
use Berkayk\OneSignal\OneSignalFacade;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class NotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */


    public function __construct(
        public string $title,
        public string $message,
        public array|null $device_ids = null,
        public ?array $data = null,
        public array $tags = [],
        public string|null $small_picture = null,
        public string|null $big_picture = null,
        public string|null $channel = null,
        public string $ios_badge_type = "Increase",
        public int $ios_badge_count = 1,
        public string $android_sound = "normal_booking.mp3",
        public string $ios_sound = "normal_booking.mp3",
        public string|null $next_business_time = null,
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $params = [
            'contents' => ["en" => $this->message],
            'headings' => ["en" => $this->title],
            'data' => $this->data ?? ['type' => 'notification'],
            'large_icon' => $this->small_picture ?? asset('images/logo/logo.png'),
            'tags'           => $this->tags,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => $this->ios_badge_count,
            'android_sound'  => $this->android_sound,
            'ios_sound'      => $this->ios_sound,
            "ios_badgeType" => $this->ios_badge_type
        ];

        if (!empty($this->next_business_time)) {
            $fields['send_after'] = $this->next_business_time;
        }

        if (!empty($this->device_ids)) {
            $params['include_player_ids'] = $this->device_ids;
        } else {
            $params['included_segments'] = ['All'];
        }

        if ($this->channel) {
            $params['android_channel_id'] = $this->channel;
        }

        if ($this->big_picture != null) {
            $params['big_picture'] = $this->big_picture;
            // $params['ios_attachments'] = ['id' => asset($img)];
        }

        try {
            $env = env('APP_ENV');
            $onesignalAppID = config('app.' . ($env == 'prod' ? 'prod' : 'test') . 'OnesignalAppID');
            $params['app_id'] = $onesignalAppID;

            $signal = OneSignalFacade::sendNotificationCustom($params);
            return $signal;
        } catch (Exception $e) {
            throw $e;
        }
    }
}
