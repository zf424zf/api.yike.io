<?php

namespace App;

use App\Mail\MailConfirmation;
use App\Mail\UserActivation;
use App\Mail\UserForgetPassword;
use App\Traits\WithDiffForHumanTimes;
use EloquentFilter\Filterable;
use Illuminate\Support\Facades\Mail;
use Overtrue\LaravelFollow\Traits\CanVote;
use Spatie\Activitylog\Models\Activity;
use UrlSigner;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Overtrue\LaravelFollow\Traits\CanFavorite;
use Overtrue\LaravelFollow\Traits\CanFollow;
use Overtrue\LaravelFollow\Traits\CanLike;
use Overtrue\LaravelFollow\Traits\CanSubscribe;
use Overtrue\LaravelFollow\Traits\CanBeFollowed;

/**
 * Class User.
 *
 * @author overtrue <i@overtrue.me>
 *
 * @property string $name
 * @property string $username
 * @property string $email
 * @property string $password
 * @property string $avatar
 * @property string $realname
 * @property string $bio
 * @property object $extends
 * @property object $settings
 * @property int $level
 * @property bool $is_admin
 * @property bool $has_banned
 * @property bool $has_activated
 * @property object $cache
 * @property string $github_id
 * @property string $linkedin_id
 * @property string $twitter_id
 * @property string $weibo_url
 * @property \Carbon\Carbon $last_active_at
 * @property \Carbon\Carbon $banned_at
 * @property \Carbon\Carbon $activated_at
 * @property \Illuminate\Database\Eloquent\Relations\HasMany $profiles
 * @property \Illuminate\Database\Eloquent\Relations\HasMany $threads
 * @property \Illuminate\Database\Eloquent\Relations\HasMany $comments
 * @property \Illuminate\Notifications\DatabaseNotificationCollection $unreadNotifications
 * @property \Illuminate\Notifications\DatabaseNotificationCollection $notifications
 * @method static \App\User popular()
 * @method static \App\User recent()
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable, Filterable, CanFavorite, CanLike, CanFollow, CanVote, CanSubscribe, CanBeFollowed, WithDiffForHumanTimes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'username', 'email', 'password', 'avatar', 'realname', 'phone',
        'bio', 'extends', 'settings', 'level', 'is_admin', 'cache', 'gender',
        'last_active_at', 'banned_at', 'activated_at',
    ];

    const CACHE_FIELDS = [
        'threads_count' => 0,
        'comments_count' => 0,
        'likes_count' => 0,
        'followings_count' => 0,
        'followers_count' => 0,
        'subscriptions_count' => 0,
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'phone',
    ];

    protected $dates = [
        'last_active_at', 'banned_at', 'activated_at',
    ];

    protected $casts = [
        'id' => 'int',
        'is_admin' => 'bool',
        'extends' => 'json',
        'cache' => 'json',
        'settings' => 'json',
    ];

    protected $appends = [
        'has_banned', 'has_activated', 'has_followed', 'followers_count', 'followings_count', 'created_at_timeago', 'updated_at_timeago',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            $user->name = $user->name ?? $user->username;

            while (User::whereUsername($user->username)->exists()) {
                $user->username = $user->username.\random_int(1, 200);
            }
        });

        static::saving(function($user){
            if (Hash::needsRehash($user->password)) {
                $user->password = \bcrypt($user->password);
            }
        });
    }

    public function profiles()
    {
        return $this->hasMany(Profile::class);
    }

    public function threads()
    {
        return $this->hasMany(Thread::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'causer_id')->with('subject')->latest();
    }

    public function scopeRecent($query)
    {
        return $query->latest();
    }

    public function scopePopular($query)
    {
        return $query->latest('');
    }

    public function getHasBannedAttribute()
    {
        return (bool) $this->banned_at;
    }

    public function getHasActivatedAttribute()
    {
        return (bool) $this->activated_at;
    }

    public function getAvatarAttribute()
    {
        if (empty($this->attributes['avatar'])) {
            $filename = \sprintf('avatars/%s.png', $this->id);
            $filepath = \storage_path('app/public/'.$filename);

            if (!\is_dir(\dirname($filepath))) {
                \mkdir(\dirname($filepath), 0755, true);
            }

            \Avatar::create($this->name)->save(Storage::disk('public')->path($filename));

            $this->update(['avatar' => \asset(\sprintf('storage/%s', $filename))]);
        }

        return $this->attributes['avatar'];
    }

    public function getHasFollowedAttribute()
    {
        return $this->isFollowedBy(auth()->user());
    }

    public function getFollowersCountAttribute()
    {
        return $this->followers()->count();
    }

    public function getFollowingsCountAttribute()
    {
        return $this->followings()->count();
    }

    public function getCacheAttribute()
    {
        return \array_merge(self::CACHE_FIELDS, \json_decode($this->attributes['cache'] ?? '{}', true));
    }

    public function getRouteKeyName()
    {
        return 'username';
    }

    public function sendActiveMail()
    {
        return Mail::to($this->email)->queue(new UserActivation($this));
    }

    public function sendUpdateMail(string $email)
    {
        return Mail::to($email)->queue(new MailConfirmation($this, $email));
    }

    public function sendPasswordResetNotification($token)
    {
        return Mail::to($this->email)->queue(new UserForgetPassword($this->email, $token));
    }

    public function getActivationLink()
    {
        return UrlSigner::sign(route('user.activate').'?'.http_build_query(['email' => $this->email]), 60);
    }

    public function getUpdateMailLink(string $email)
    {
        $params = http_build_query([
            'email' => $email,
            'user_id' => $this->id,
        ]);

        return UrlSigner::sign(route('user.update-email').'?'.$params, 60);
    }

    public function activate()
    {
        return $this->update(['activated_at' => now()]);
    }

    public function refreshCache()
    {
        $this->update([
            'cache->threads_count' => $this->threads()->count(),
            'cache->comments_count' => $this->comments()->count(),
            'cache->likes_count' => $this->likes()->count(),
            'cache->followings_count' => $this->followings()->count(),
            'cache->followers_count' => $this->followers()->count(),
            'cache->subscriptions_count' => $this->subscriptions()->count(),
        ]);
    }

    /**
     * Find the user identified by the given $identifier.
     *
     * @param $identifier email|phone
     * @return mixed
     */
    public function findForPassport($identifier) {
        return User::orWhere('email', $identifier)->orWhere('username', $identifier)->first();
    }
}
