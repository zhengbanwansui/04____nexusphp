<?php
namespace App\Repositories;

use App\Exceptions\NexusException;
use App\Models\BonusLogs;
use App\Models\CustomLoanRepayment;
use App\Models\HitAndRun;
use App\Models\Invite;
use App\Models\Medal;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\TorrentBuyLog;
use App\Models\User;
use App\Models\UserMedal;
use App\Models\UserMeta;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Nexus\Database\NexusDB;

class BonusRepository extends BaseRepository
{
    public function consumeToCancelHitAndRun($uid, $hitAndRunId): bool
    {
        if (!HitAndRun::getIsEnabled()) {
            throw new \LogicException("H&R not enabled.");
        }
        $user = User::query()->findOrFail($uid);
        $hitAndRun = HitAndRun::query()->findOrFail($hitAndRunId);
        if ($hitAndRun->uid != $uid) {
            throw new \LogicException("H&R: $hitAndRunId not belongs to user: $uid.");
        }
        if ($hitAndRun->status == HitAndRun::STATUS_PARDONED) {
            throw new \LogicException("H&R: $hitAndRunId already pardoned.");
        }
        $requireBonus = BonusLogs::getBonusForCancelHitAndRun();
        NexusDB::transaction(function () use ($user, $hitAndRun, $requireBonus) {
            $comment = nexus_trans('hr.bonus_cancel_comment', [
                'bonus' => $requireBonus,
            ], $user->locale);
            do_log("comment: $comment");

            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_CANCEL_HIT_AND_RUN, "$comment(H&R ID: {$hitAndRun->id})");

            $hitAndRun->update([
                'status' => HitAndRun::STATUS_PARDONED,
                'comment' => NexusDB::raw("if(comment = '', '$comment', concat_ws('\n', '$comment', comment))"),
            ]);
        });

        return true;

    }


    public function consumeToBuyMedal($uid, $medalId): bool
    {
        $user = User::query()->findOrFail($uid);
        $medal = Medal::query()->findOrFail($medalId);
        $exists = $user->valid_medals()->where('medal_id', $medalId)->exists();
        do_log(last_query());
        if ($exists) {
            throw new \LogicException("user: $uid already own this medal: $medalId.");
        }
        $medal->checkCanBeBuy();
        $requireBonus = $medal->price;
        NexusDB::transaction(function () use ($user, $medal, $requireBonus) {
            $comment = nexus_trans('bonus.comment_buy_medal', [
                'bonus' => $requireBonus,
                'medal_name' => $medal->name,
            ], $user->locale);
            do_log("comment: $comment");
            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_BUY_MEDAL, "$comment(medal ID: {$medal->id})");
            $expireAt = null;
            if ($medal->duration > 0) {
                $expireAt = Carbon::now()->addDays($medal->duration)->toDateTimeString();
            }
            $user->medals()->attach([$medal->id => ['expire_at' => $expireAt, 'status' => UserMedal::STATUS_NOT_WEARING]]);
            if ($medal->inventory !== null) {
                $affectedRows = NexusDB::table('medals')
                    ->where('id', $medal->id)
                    ->where('inventory', $medal->inventory)
                    ->decrement('inventory')
                ;
                if ($affectedRows != 1) {
                    throw new \RuntimeException("Decrement medal({$medal->id}) inventory affected rows != 1($affectedRows)");
                }
            }

        });

        return true;

    }

    public function consumeToGiftMedal($uid, $medalId, $toUid): bool
    {
        $user = User::query()->findOrFail($uid);
        $toUser = User::query()->findOrFail($toUid);
        $medal = Medal::query()->findOrFail($medalId);
        $exists = $toUser->valid_medals()->where('medal_id', $medalId)->exists();
        do_log(last_query());
        if ($exists) {
            throw new \LogicException("user: $toUid already own this medal: $medalId.");
        }
        $medal->checkCanBeBuy();
        $giftFee = $medal->price * ($medal->gift_fee_factor ?? 0);
        $requireBonus = $medal->price + $giftFee;
        NexusDB::transaction(function () use ($user, $toUser, $medal, $requireBonus, $giftFee) {
            $comment = nexus_trans('bonus.comment_gift_medal', [
                'bonus' => $requireBonus,
                'medal_name' => $medal->name,
                'to_username' => $toUser->username,
            ], $user->locale);
            do_log("comment: $comment");
            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_GIFT_MEDAL, "$comment(medal ID: {$medal->id})");

            $expireAt = null;
            if ($medal->duration > 0) {
                $expireAt = Carbon::now()->addDays($medal->duration)->toDateTimeString();
            }
            $msg = [
                'sender' => 0,
                'receiver' => $toUser->id,
                'subject' => nexus_trans('message.receive_medal.subject', [], $toUser->locale),
                'msg' => nexus_trans('message.receive_medal.body', [
                    'username' => $user->username,
                    'cost_bonus' => $requireBonus,
                    'medal_name' => $medal->name,
                    'price' => $medal->price,
                    'gift_fee_total' => $giftFee,
                    'gift_fee_factor' => $medal->gift_fee_factor ?? 0,
                    'expire_at' => $expireAt ?? nexus_trans('label.permanent'),
                    'bonus_addition_factor' => $medal->bonus_addition_factor ?? 0,
                ], $toUser->locale),
                'added' => now()
            ];
            Message::add($msg);
            $toUser->medals()->attach([$medal->id => ['expire_at' => $expireAt, 'status' => UserMedal::STATUS_NOT_WEARING]]);
            if ($medal->inventory !== null) {
                $affectedRows = NexusDB::table('medals')
                    ->where('id', $medal->id)
                    ->where('inventory', $medal->inventory)
                    ->decrement('inventory')
                ;
                if ($affectedRows != 1) {
                    throw new \RuntimeException("Decrement medal({$medal->id}) inventory affected rows != 1($affectedRows)");
                }
            }

        });

        return true;

    }

    public function consumeToBuyAttendanceCard($uid): bool
    {
        $user = User::query()->findOrFail($uid);
        $requireBonus = BonusLogs::getBonusForBuyAttendanceCard();
        NexusDB::transaction(function () use ($user, $requireBonus) {
            $comment = nexus_trans('bonus.comment_buy_attendance_card', [
                'bonus' => $requireBonus,
            ], $user->locale);
            do_log("comment: $comment");
            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_BUY_ATTENDANCE_CARD, $comment);
            User::query()->where('id', $user->id)->increment('attendance_card');
        });

        return true;

    }


    public function consumeToBuyTemporaryInvite($uid, $count = 1): bool
    {
        $requireBonus = BonusLogs::getBonusForBuyTemporaryInvite();
        if ($requireBonus <= 0) {
            throw new \RuntimeException("Temporary invite require bonus <= 0 !");
        }
        $user = User::query()->findOrFail($uid);
        $toolRep = new ToolRepository();
        $hashArr = $toolRep->generateUniqueInviteHash([], $count, $count);
        NexusDB::transaction(function () use ($user, $requireBonus, $hashArr) {
            $comment = nexus_trans('bonus.comment_buy_temporary_invite', [
                'bonus' => $requireBonus,
                'count' => count($hashArr)
            ], $user->locale);
            do_log("comment: $comment");
            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_BUY_TEMPORARY_INVITE, $comment);
            $invites = [];
            foreach ($hashArr as $hash) {
                $invites[] = [
                    'inviter' => $user->id,
                    'invitee' => '',
                    'hash' => $hash,
                    'valid' => 0,
                    'expired_at' => Carbon::now()->addDays(Invite::TEMPORARY_INVITE_VALID_DAYS),
                    'created_at' => Carbon::now(),
                ];
            }
            Invite::query()->insert($invites);
        });

        return true;

    }

    public function consumeToBuyRainbowId($uid, $duration = 30): bool
    {
        $user = User::query()->findOrFail($uid);
        $requireBonus = BonusLogs::getBonusForBuyRainbowId();
        NexusDB::transaction(function () use ($user, $requireBonus, $duration) {
            $comment = nexus_trans('bonus.comment_buy_rainbow_id', [
                'bonus' => $requireBonus,
                'duration' => $duration,
            ], $user->locale);
            do_log("comment: $comment");
            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_BUY_RAINBOW_ID, $comment);
            $metaData = [
                'meta_key' => UserMeta::META_KEY_PERSONALIZED_USERNAME,
                'duration' => $duration,
            ];
            $userRep = new UserRepository();
            $userRep->addMeta($user, $metaData, $metaData, false);
        });

        return true;

    }

    public function consumeToBuyChangeUsernameCard($uid): bool
    {
        $user = User::query()->findOrFail($uid);
        $requireBonus = BonusLogs::getBonusForBuyChangeUsernameCard();
        if (UserMeta::query()->where('uid', $uid)->where('meta_key', UserMeta::META_KEY_CHANGE_USERNAME)->exists()) {
            throw new NexusException("user already has change username card");
        }
        NexusDB::transaction(function () use ($user, $requireBonus) {
            $comment = nexus_trans('bonus.comment_buy_change_username_card', [
                'bonus' => $requireBonus,
            ], $user->locale);
            do_log("comment: $comment");
            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_BUY_CHANGE_USERNAME_CARD, $comment);
            $metaData = [
                'meta_key' => UserMeta::META_KEY_CHANGE_USERNAME,
            ];
            $userRep = new UserRepository();
            $userRep->addMeta($user, $metaData, $metaData, false);
        });

        return true;

    }

    public function consumeToBuyTorrent($uid, $torrentId, $channel = 'Web'): bool
    {
        $torrent = Torrent::query()->findOrFail($torrentId, Torrent::$commentFields);
        $requireBonus = $torrent->price;
        NexusDB::transaction(function () use ($requireBonus, $torrent, $channel, $uid) {
            $userQuery = User::query();
            if ($requireBonus > 0) {
                $userQuery = $userQuery->lockForUpdate();
            }
            $user = $userQuery->findOrFail($uid);
            $buyerLocale = $user->locale;
            $comment = nexus_trans('bonus.comment_buy_torrent', [
                'bonus' => $requireBonus,
                'torrent_id' => $torrent->id,
            ], $buyerLocale);
            do_log("comment: $comment");
            $this->consumeUserBonus($user, $requireBonus, BonusLogs::BUSINESS_TYPE_BUY_TORRENT, $comment);
            TorrentBuyLog::query()->create([
                'uid' => $user->id,
                'torrent_id' => $torrent->id,
                'price' => $requireBonus,
                'channel' => $channel,
            ]);
            //increment owner bonus
            $taxFactor = Setting::get('torrent.tax_factor');
            if (!is_numeric($taxFactor) || $taxFactor < 0 || $taxFactor > 1) {
                throw new \RuntimeException("Invalid tax_factor: $taxFactor");
            }
            $increaseBonus = $requireBonus * (1 - $taxFactor);
            $owner = $torrent->user;
            if ($owner->id) {
                $nowStr = now()->toDateTimeString();
                $businessType = BonusLogs::BUSINESS_TYPE_TORRENT_BE_DOWNLOADED;
                $owner->increment('seedbonus', $increaseBonus);
                $comment = nexus_trans('bonus.comment_torrent_be_downloaded', [
                    'username' => $user->username,
                    'uid' => $user->id,
                ], $owner->locale);
                $bonusLog = [
                    'business_type' => $businessType,
                    'uid' => $owner->id,
                    'old_total_value' => $owner->seedbonus,
                    'value' => $increaseBonus,
                    'new_total_value' => bcadd($owner->seedbonus, $increaseBonus),
                    'comment' => sprintf('[%s] %s', BonusLogs::$businessTypes[$businessType]['text'], $comment),
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
                BonusLogs::query()->insert($bonusLog);
            }
            $buyTorrentSuccessMessage = [
                'sender' => 0,
                'receiver' => $user->id,
                'added' => now(),
                'subject' => nexus_trans("message.buy_torrent_success.subject", [], $buyerLocale),
                'msg' => nexus_trans("message.buy_torrent_success.body", [
                    'torrent_name' => $torrent->name,
                    'bonus' => $requireBonus,
                    'url' => sprintf('details.php?id=%s&hit=1', $torrent->id)
                ], $buyerLocale),
            ];
            Message::add($buyTorrentSuccessMessage);
        });

        return true;

    }

    public function consumeUserBonus($user, $requireBonus, $logBusinessType, $logComment = '', array $userUpdates = [])
    {
        if (!isset(BonusLogs::$businessTypes[$logBusinessType])) {
            throw new \InvalidArgumentException("Invalid logBusinessType: $logBusinessType");
        }
        if (isset($userUpdates['seedbonus']) || isset($userUpdates['bonuscomment'])) {
            throw new \InvalidArgumentException("Not support update seedbonus or bonuscomment");
        }
        if ($requireBonus <= 0) {
            return;
        }
        $user = $this->getUser($user);
        if ($user->seedbonus < $requireBonus) {
            do_log("user: {$user->id}, bonus: {$user->seedbonus} < requireBonus: $requireBonus", 'error');
            throw new \LogicException("User bonus not enough.");
        }
        NexusDB::transaction(function () use ($user, $requireBonus, $logBusinessType, $logComment, $userUpdates) {
            $logComment = addslashes($logComment);
            $bonusComment = date('Y-m-d') . " - $logComment";
            $oldUserBonus = $user->seedbonus;
            $newUserBonus = bcsub($oldUserBonus, $requireBonus);
            $log = "user: {$user->id}, requireBonus: $requireBonus, oldUserBonus: $oldUserBonus, newUserBonus: $newUserBonus, logBusinessType: $logBusinessType, logComment: $logComment";
            do_log($log);
            $userUpdates['seedbonus'] = $newUserBonus;
            $userUpdates['bonuscomment'] = NexusDB::raw("if(bonuscomment = '', '$bonusComment', concat_ws('\n', '$bonusComment', bonuscomment))");
            $affectedRows = NexusDB::table($user->getTable())
                ->where('id', $user->id)
                ->where('seedbonus', $oldUserBonus)
                ->update($userUpdates);
            if ($affectedRows != 1) {
                do_log("update user seedbonus affected rows != 1, query: " . last_query(), 'error');
                throw new \RuntimeException("Update user seedbonus fail.");
            }
            $nowStr = now()->toDateTimeString();
            $bonusLog = [
                'business_type' => $logBusinessType,
                'uid' => $user->id,
                'old_total_value' => $oldUserBonus,
                'value' => $requireBonus,
                'new_total_value' => $newUserBonus,
                'comment' => sprintf('[%s] %s', BonusLogs::$businessTypes[$logBusinessType]['text'], $logComment),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
            BonusLogs::query()->insert($bonusLog);
            do_log("bonusLog: " . nexus_json_encode($bonusLog));
            clear_user_cache($user->id, $user->passkey);
        });
    }

    // 贷款
    public function customLoan($user, $requireBonus, $logBusinessType, $logComment = '', array $userUpdates = []) {
        // 各种异常
        if (!isset(BonusLogs::$businessTypes[$logBusinessType])) {
            throw new \InvalidArgumentException("Invalid logBusinessType: $logBusinessType");
        }
        if (isset($userUpdates['seedbonus']) || isset($userUpdates['bonuscomment'])) {
            throw new \InvalidArgumentException("Not support update seedbonus or bonuscomment");
        }
        if ($requireBonus <= 0) {
            return;
        }
        // 获取用户对象
        $user = $this->getUser($user);
        // 检查用户对象的钱包够不够买, 贷款不需要考虑此项
//        if ($user->seedbonus < $requireBonus) {
//            do_log("user: {$user->id}, bonus: {$user->seedbonus} < requireBonus: $requireBonus", 'error');
//            throw new \LogicException("User bonus not enough.");
//        }
        // MySQL事务
        NexusDB::transaction(function () use ($user, $requireBonus, $logBusinessType, $logComment, $userUpdates) {
            // 反斜杠转义
            $logComment = addslashes($logComment);
            // 前加年月日-
            $bonusComment = date('Y-m-d') . " - $logComment";
            // 用户原有魔力
            $oldUserBonus = $user->seedbonus;
            // 用户贷款后有魔力(浮点数相加)
            $newUserBonus = bcadd($oldUserBonus, $requireBonus);
            // 日志信息构建和输出内部日志
            $log = "user: {$user->id}, requireBonus: $requireBonus, oldUserBonus: $oldUserBonus, newUserBonus: $newUserBonus, logBusinessType: $logBusinessType, logComment: $logComment";
            // 后台日志输出 /tmp/nexus/nexus-20231125.txt
            do_log($log);
            // 构建update对象
            // 参数1 用户的魔力值为贷款后新的魔力值
            $userUpdates['seedbonus'] = $newUserBonus;
            // 参数2 追加新记录到bonuscomment的前面，bounscomment是一个文档包括多行， 新纪录往前面加一行
            $userUpdates['bonuscomment'] = NexusDB::raw("if(bonuscomment = '', '$bonusComment', concat_ws('\n', '$bonusComment', bonuscomment))");
            // 执行sql更新用户表
            $affectedRows = NexusDB::table($user->getTable())
                ->where('id', $user->id)
                ->where('seedbonus', $oldUserBonus)
                ->update($userUpdates);
            if ($affectedRows != 1) {
                // 超时了或者错误了
                do_log("update user seedbonus affected rows != 1, query: " . last_query(), 'error');
                throw new \RuntimeException("Update user seedbonus fail.");
            }
            // nowStr = new Date();
            $nowStr = now()->toDateTimeString();
            $bonusLog = [
                'business_type' => $logBusinessType,
                'uid' => $user->id,
                'old_total_value' => $oldUserBonus,
                'value' => $requireBonus,
                'new_total_value' => $newUserBonus,
                'comment' => sprintf('[%s] %s', BonusLogs::$businessTypes[$logBusinessType]['text'], $logComment),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
            // 插入数据表bonus_logs
            BonusLogs::query()->insert($bonusLog);
            // 保存贷款记录
            $loanDO = [
                'user_id' => $user->id,
                'seedbonus' => $requireBonus,
                'comment' => 'loan [' . $requireBonus . '] seedbonus.'
            ];
            CustomLoanRepayment::query()->insert($loanDO);
            // 系统日志
            do_log("bonusLog: " . nexus_json_encode($bonusLog));
            // 清除缓存
            clear_user_cache($user->id, $user->passkey);
        });
    }

    // 还款
    public function customRepayment($user, $requireBonus, $logBusinessType, $logComment = '', array $userUpdates = []) {
        // 各种异常
        if (!isset(BonusLogs::$businessTypes[$logBusinessType])) {
            throw new \InvalidArgumentException("Invalid logBusinessType: $logBusinessType");
        }
        if (isset($userUpdates['seedbonus']) || isset($userUpdates['bonuscomment'])) {
            throw new \InvalidArgumentException("Not support update seedbonus or bonuscomment");
        }
        if ($requireBonus <= 0) {
            return;
        }
        // 获取用户对象
        $user = $this->getUser($user);
        // 检查用户对象的钱包够不够买
        if ($user->seedbonus < $requireBonus) {
            do_log("user: {$user->id}, bonus: {$user->seedbonus} < requireBonus: $requireBonus", 'error');
            throw new \LogicException("User bonus not enough.");
        }
        // MySQL事务
        NexusDB::transaction(function () use ($user, $requireBonus, $logBusinessType, $logComment, $userUpdates) {
            // 反斜杠转义
            $logComment = addslashes($logComment);
            // 前加年月日-
            $bonusComment = date('Y-m-d') . " - $logComment";
            // 用户原有魔力
            $oldUserBonus = $user->seedbonus;
            // 用户贷款后有魔力(浮点数相加)
            $newUserBonus = bcsub($oldUserBonus, $requireBonus);
            // 日志信息构建和输出内部日志
            $log = "user: {$user->id}, requireBonus: $requireBonus, oldUserBonus: $oldUserBonus, newUserBonus: $newUserBonus, logBusinessType: $logBusinessType, logComment: $logComment";
            // 后台日志输出 /tmp/nexus/nexus-20231125.txt
            do_log($log);
            // 构建update对象
            // 参数1 用户的魔力值为贷款后新的魔力值
            $userUpdates['seedbonus'] = $newUserBonus;
            // 参数2 追加新记录到bonuscomment的前面，bounscomment是一个文档包括多行， 新纪录往前面加一行
            $userUpdates['bonuscomment'] = NexusDB::raw("if(bonuscomment = '', '$bonusComment', concat_ws('\n', '$bonusComment', bonuscomment))");
            // 执行sql更新用户表
            $affectedRows = NexusDB::table($user->getTable())
                ->where('id', $user->id)
                ->where('seedbonus', $oldUserBonus)
                ->update($userUpdates);
            if ($affectedRows != 1) {
                // 超时了或者错误了
                do_log("update user seedbonus affected rows != 1, query: " . last_query(), 'error');
                throw new \RuntimeException("Update user seedbonus fail.");
            }
            // nowStr = new Date();
            $nowStr = now()->toDateTimeString();
            $bonusLog = [
                'business_type' => $logBusinessType,
                'uid' => $user->id,
                'old_total_value' => $oldUserBonus,
                'value' => $requireBonus,
                'new_total_value' => $newUserBonus,
                'comment' => sprintf('[%s] %s', BonusLogs::$businessTypes[$logBusinessType]['text'], $logComment),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
            // 插入数据表bonus_logs
            BonusLogs::query()->insert($bonusLog);
            // 删除贷款记录
            $record = CustomLoanRepayment::query() -> where("user_id", $user->id);
            $record->delete();
            // 系统日志
            do_log("bonusLog: " . nexus_json_encode($bonusLog));
            // 清除缓存
            clear_user_cache($user->id, $user->passkey);
        });
    }

    // 进货大头菜
    public function customBuyTurnip($user, $requireBonus, $num, $logBusinessType, $logComment = '', array $userUpdates = []) {
        // 各种异常
        if (!isset(BonusLogs::$businessTypes[$logBusinessType])) {
            throw new \InvalidArgumentException("Invalid logBusinessType: $logBusinessType");
        }
        if (isset($userUpdates['seedbonus']) || isset($userUpdates['bonuscomment'])) {
            throw new \InvalidArgumentException("Not support update seedbonus or bonuscomment");
        }
        if ($requireBonus <= 0) {
            return;
        }
        // 获取用户对象
        $user = $this->getUser($user);
        // 检查用户对象的钱包够不够买
        if ($user->seedbonus < $requireBonus * $num) {
            do_log("user: {$user->id}, bonus: {$user->seedbonus} < requireBonus: $requireBonus * $num", 'error');
            throw new \LogicException("User bonus not enough.");
        }
        // MySQL事务
        NexusDB::transaction(function () use ($user, $requireBonus, $num, $logBusinessType, $logComment, $userUpdates) {
            // 反斜杠转义
            $logComment = addslashes($logComment);
            // 前加年月日-
            $bonusComment = date('Y-m-d') . " - $logComment";
            // 用户原有魔力
            $oldUserBonus = $user->seedbonus;
            // 用户进货后有魔力(浮点数相加)
            $newUserBonus = bcsub($oldUserBonus, $requireBonus*$num);
            // 日志信息构建和输出内部日志
            $log = "user: {$user->id}, requireBonus: $requireBonus, oldUserBonus: $oldUserBonus, newUserBonus: $newUserBonus, logBusinessType: $logBusinessType, logComment: $logComment";
            // 后台日志输出 /tmp/nexus/nexus-20231125.txt
            do_log($log);
            // 构建update对象
            // 参数1 用户新的魔力值
            $userUpdates['seedbonus'] = $newUserBonus;
            // 参数2 追加新记录到bonuscomment的前面，bounscomment是一个文档包括多行， 新纪录往前面加一行
            $userUpdates['bonuscomment'] = NexusDB::raw("if(bonuscomment = '', '$bonusComment', concat_ws('\n', '$bonusComment', bonuscomment))");
            // 执行sql更新用户表
            $affectedRows = NexusDB::table($user->getTable())
                ->where('id', $user->id)
                ->where('seedbonus', $oldUserBonus)
                ->update($userUpdates);
            if ($affectedRows != 1) {
                // 超时了或者错误了
                do_log("update user seedbonus affected rows != 1, query: " . last_query(), 'error');
                throw new \RuntimeException("Update user seedbonus fail.");
            }
            // 入库或更新custom_turnip表
            $oldRecord = NexusDB::table("custom_turnip")->where('user_id', $user->id)->where('created_at', '>', getLastSunday())->first();
            if ($oldRecord === null) {
                $tmpDate = date('Y-m-d H:i:s');
                NexusDB::table("custom_turnip")->insert([
                    'id' => time(),
                    'username' => $user->username,
                    'user_id' => $user->id,
                    'created_at' => $tmpDate,
                    'updated_at' => $tmpDate,
                    'comment' => 'buy price=' . $requireBonus . ' x ' . $num,
                    'number' => $num,
                    'price' => $requireBonus,
                    'seedbonus' => 0.0,
                ]);
            } else {
                $turnipUpdate = [];
                $turnipUpdate['number'] = $oldRecord->number + $num;
                $turnipUpdate['updated_at'] = now()->toDateTimeString();
                NexusDB::table("custom_turnip")
                    ->where('user_id', $user->id)->where('created_at', '>', getLastSunday())->update($turnipUpdate);
            }
            // 日志插入数据表bonus_logs
            $nowStr = now()->toDateTimeString();
            $bonusLog = [
                'business_type' => $logBusinessType,
                'uid' => $user->id,
                'old_total_value' => $oldUserBonus,
                'value' => $requireBonus,
                'new_total_value' => $newUserBonus,
                'comment' => sprintf('[%s] %s', BonusLogs::$businessTypes[$logBusinessType]['text'], $logComment),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
            BonusLogs::query()->insert($bonusLog);
            // 系统日志
            do_log("bonusLog: " . nexus_json_encode($bonusLog));
            // 清除缓存
            clear_user_cache($user->id, $user->passkey);
        });
    }

    // 出售
    public function customSaleTurnip($user, $requireBonus, $num, $logBusinessType, $logComment = '', array $userUpdates = []) {
        // 各种异常
        if (!isset(BonusLogs::$businessTypes[$logBusinessType])) {
            throw new \InvalidArgumentException("Invalid logBusinessType: $logBusinessType");
        }
        if (isset($userUpdates['seedbonus']) || isset($userUpdates['bonuscomment'])) {
            throw new \InvalidArgumentException("Not support update seedbonus or bonuscomment");
        }
        // 获取用户对象
        $user = $this->getUser($user);
        // MySQL事务
        NexusDB::transaction(function () use ($user, $requireBonus, $num, $logBusinessType, $logComment, $userUpdates) {
            // 反斜杠转义
            $logComment = addslashes($logComment);
            // 前加年月日-
            $bonusComment = date('Y-m-d') . " - $logComment";
            // 用户原有魔力
            $oldUserBonus = $user->seedbonus;
            // 商品出售后有魔力(浮点数相加)
            $newUserBonus = bcadd($oldUserBonus, $requireBonus*$num);
            // 日志信息构建和输出内部日志
            $log = "user: {$user->id}, requireBonus: $requireBonus, oldUserBonus: $oldUserBonus, newUserBonus: $newUserBonus, logBusinessType: $logBusinessType, logComment: $logComment";
            // 后台日志输出 /tmp/nexus/nexus-20231125.txt
            do_log($log);
            // 构建update对象
            // 参数1 用户新的魔力值
            $userUpdates['seedbonus'] = $newUserBonus;
            // 参数2 追加新记录到bonuscomment的前面，bounscomment是一个文档包括多行， 新纪录往前面加一行
            $userUpdates['bonuscomment'] = NexusDB::raw("if(bonuscomment = '', '$bonusComment', concat_ws('\n', '$bonusComment', bonuscomment))");
            // 执行sql更新用户表
            $affectedRows = NexusDB::table($user->getTable())
                ->where('id', $user->id)
                ->where('seedbonus', $oldUserBonus)
                ->update($userUpdates);
            if ($affectedRows != 1) {
                // 超时了或者错误了
                do_log("update user seedbonus affected rows != 1, query: " . last_query(), 'error');
                throw new \RuntimeException("Update user seedbonus fail.");
            }
            // 处理custom_turnip表
            $oldRecord = NexusDB::table("custom_turnip")->where('user_id', $user->id)->where('created_at', '>', getLastSunday())->first();
            // 没数据
            if ($oldRecord === null) {
                NexusDB::rollback(); // 手动回滚事务
                return; // 退出事务
            }
            // 数量不够
            elseif($oldRecord->number < $num) {
                NexusDB::rollback(); // 手动回滚事务
                return; // 退出事务
            }
            // 正常减少库存
            else {
                $turnipUpdate = [];
                $turnipUpdate['number'] = $oldRecord->number - $num;
                $turnipUpdate['updated_at'] = now()->toDateTimeString();
                NexusDB::table("custom_turnip")
                    ->where('user_id', $user->id)->where('created_at', '>', getLastSunday())->update($turnipUpdate);
            }
            // 日志插入数据表bonus_logs
            $nowStr = now()->toDateTimeString();
            $bonusLog = [
                'business_type' => $logBusinessType,
                'uid' => $user->id,
                'old_total_value' => $oldUserBonus,
                'value' => $requireBonus,
                'new_total_value' => $newUserBonus,
                'comment' => sprintf('[%s] %s', BonusLogs::$businessTypes[$logBusinessType]['text'], $logComment),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
            BonusLogs::query()->insert($bonusLog);
            // 系统日志
            do_log("bonusLog: " . nexus_json_encode($bonusLog));
            // 清除缓存
            clear_user_cache($user->id, $user->passkey);
        });
    }

    function getLastSunday() {
        $today = date('Y-m-d');
        $previousWeekend = date('Y-m-d', strtotime('last Sunday', strtotime($today)));

        if (date('N', strtotime($today)) == 7) {
            // 如果今天是周末，则使用今天的日期
            $startTime = $today . ' 00:00:00';
        } else {
            // 如果今天不是周末，则使用上一个周末的日期
            $startTime = $previousWeekend . ' 00:00:00';
        }
        return $startTime;
    }
}
