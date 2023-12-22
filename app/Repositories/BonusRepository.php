<?php
namespace App\Repositories;

use App\Exceptions\NexusException;
use App\Models\BonusLogs;
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
        // 校验
        if (!is_numeric($requireBonus) || $requireBonus < 10000 || $requireBonus > getMaxLoan()) {
            return;
        }
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
                'id' => $user->id,
                'user_id' => $user->id,
                'seedbonus' => $requireBonus,
                'comment' => 'loan [' . $requireBonus . '] seedbonus.'
            ];
            NexusDB::table('custom_loan_repayment')->insert($loanDO);
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
        // 获取用户对象
        $user = $this->getUser($user);
        // MySQL事务
        NexusDB::transaction(function () use ($user, $logBusinessType, $logComment, $userUpdates) {
            // 计算总欠款
            $loanObj = NexusDB::table("custom_loan_repayment")->where("user_id", $user->id)->first();
            if ($loanObj === null) {
                throw new \RuntimeException("没有欠款信息, 无法还贷");
            }
            $totalToRepay = totalToRepay($user->id);
            // 反斜杠转义
            $logComment = addslashes($logComment);
            // 前加年月日-
            $bonusComment = date('Y-m-d') . " - $logComment";
            // 用户原有魔力
            $oldUserBonus = $user->seedbonus;
            // 用户还款后有魔力
            $newUserBonus = bcsub($oldUserBonus, $totalToRepay);
            if ($newUserBonus < 0) {
                throw new \RuntimeException("钱不够, 无法还贷");
                return;
            }
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
            // 删除贷款记录
            NexusDB::table('custom_loan_repayment')-> where("user_id", $user->id)->delete();
            // 其他逻辑如下
            $nowStr = now()->toDateTimeString();
            $bonusLog = [
                'business_type' => $logBusinessType,
                'uid' => $user->id,
                'old_total_value' => $oldUserBonus,
                'value' => $totalToRepay,
                'new_total_value' => $newUserBonus,
                'comment' => sprintf('[%s] %s', BonusLogs::$businessTypes[$logBusinessType]['text'], $logComment),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
            // 插入数据表bonus_logs
            BonusLogs::query()->insert($bonusLog);
            // 系统日志
            do_log("bonusLog: " . nexus_json_encode($bonusLog));
            // 清除缓存
            clear_user_cache($user->id, $user->passkey);
        });
    }

    // 进货大头菜
    public function customBuyTurnip($user, $requireBonus, $num, $logBusinessType, $logComment = '', array $userUpdates = []) {
        // 校验
        if (getWeekDayNumber() !== "7") {
            throw new \InvalidArgumentException("只有周日能进货weekday=[".getWeekDayNumber()."]");
        }
        if (!is_numeric($num)) {
            throw new \InvalidArgumentException("数量应该是数字");
        }
        $num = intval($num);
        if ($num < 1) {
            throw new \InvalidArgumentException("数量应该>=1");
        }
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
    // 目标10元 单价3元 数量max=10/3+1=4最大卖四个
    // 界面直接限制交易数量, 后端正常处理, 前端超过盈利上限直接禁止按按钮了, 后端发现超过盈利上限, 直接自动卖掉剩余的 (后端为了保险保底如果盈利超过额外10万了直接报错就好)
    public function customSaleTurnip($user, $requireBonus, $num, $logBusinessType, $logComment = '', array $userUpdates = []) {
        // 校验
        if (getWeekDayNumber() == "7") {
            throw new \InvalidArgumentException("周日不能出售");
        }
        if (!is_numeric($num)) {
            throw new \InvalidArgumentException("数量应该是数字");
        }
        $num = intval($num);
        if ($num < 1) {
            throw new \InvalidArgumentException("数量应该>=1");
        }
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
            // 获取持仓信息
            $oldRecord = NexusDB::table("custom_turnip")->where('user_id', $user->id)->where('created_at', '>', getLastSunday())->first();
            // 计算此次交易盈利多少钱
            $profitThisTime = bcmul($num, bcsub($requireBonus, $oldRecord->price));
            // 处理盈利表
            $oldProfitObject = NexusDB::table("custom_turnip_profit")->where("user_id", $user->id)->first();
            $profitAll = 0;
            if ($oldProfitObject === null) {
                $profitAll = $profitThisTime;
                // 插入盈利信息
                NexusDB::table("custom_turnip_profit")->insert([
                    'id' => time(),
                    'username' => $user->username,
                    'user_id' => $user->id,
                    'comment' => 'create profit price=' . $profitThisTime,
                    'price' => $profitAll,
                    'created_at' => time(),
                    'updated_at' => time()
                ]);
            } else {
                $profitAll = bcadd($oldProfitObject->price, $profitThisTime);
                // 更新盈利信息
                NexusDB::table("custom_turnip_profit")->where('user_id', $user->id)->update([
                    'comment' => 'create profit price=' . $profitThisTime . "\n" . $oldProfitObject->comment,
                    'price' => $profitAll,
                    'updated_at' => time()
                ]);
            }
            // 用户原有魔力
            $oldUserBonus = $user->seedbonus;
            // 商品出售后有魔力(浮点数相加)
            $newUserBonus = bcadd($oldUserBonus, $requireBonus*$num);
            // 库存更改为多少
            $subOldRecordNumber = $oldRecord->number - $num;
            // 如果达到盈利目标, 直接自动平仓掉剩余仓位
            if ($this->getMaxProfit() <= $profitAll) {
                $newUserBonus = bcadd($newUserBonus, bcmul(($oldRecord->number - $num), $oldRecord->price));
                $subOldRecordNumber = 0;
                // 盈利目标达成全服公告
                $text = "恭喜<b style='color:#288002;'>".$user->username."的蔬菜店</b>完成了盈利目标！";
                $this->shoutbox($text);
            }

            // 反斜杠转义
            $logComment = addslashes($logComment);
            // 前加年月日-
            $bonusComment = date('Y-m-d') . " - $logComment";
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
                $turnipUpdate['number'] = $subOldRecordNumber;
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

        if (date('N', strtotime($today)) == "7") {
            // 如果今天是周末，则使用今天的日期
            $startTime = $today . ' 00:00:00';
        } else {
            // 如果今天不是周末，则使用上一个周末的日期
            $startTime = $previousWeekend . ' 00:00:00';
        }
        return $startTime;
    }

    function getMaxProfit() {
        $start_date = strtotime('2023-12-18 00:00:00');
        $today_date = strtotime(date('Y-m-d 00:00:00'));
        $days_since_start = floor(($today_date - $start_date) / (60 * 60 * 24));
        $max_loan = 500000 + $days_since_start * 10000;
        if ($days_since_start > 150) {
            $max_loan = 500000 + 150 * 10000 + ($days_since_start-150) * 5000;
        }
        return $max_loan;
    }

    function getMaxLoan() {
        return 300000;
    }

    function totalToRepay($userid) {
        $loanInfo = NexusDB::table('custom_loan_repayment')->where('user_id', $userid)->first();
        if ($loanInfo !== null) {
            // 计算贷款总利息
            $seedbonus = $loanInfo->seedbonus; // 贷款的钱数
            $createdAt = strtotime($loanInfo->created_at); // 贷款开始时间的时间戳
            $today = time(); // 今天的时间戳
            $daysPassed = floor(($today - $createdAt) / (60 * 60 * 24)); // 已经过去的天数
            $totalInterest = $seedbonus * pow(1.02, $daysPassed) - $seedbonus; // 总利息
            // 计算用户今天需要还款的总额
            $result = $seedbonus + $totalInterest; // 总共需要还的钱数
            if ($result > $seedbonus * 2) {
                return $seedbonus * 2;
            }
            // 返回还款
            return $result;
        } else {
            return 0.0;
        }
    }

    public function shoutbox($text) {
        $shoutDO = [
            "userid"=>1,
            "date"=>time(),
            "text"=>$text,
            "type"=>"sb"
        ];
        NexusDB::table("shoutbox")->insert($shoutDO);
    }

    function getWeekDayNumber() {
        return date('N');
//          return "7";
//          return "6";
    }
}
