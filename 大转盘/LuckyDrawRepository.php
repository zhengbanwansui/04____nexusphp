<?php
namespace NexusPlugin\LuckyDraw;

use App\Filament\OptionsTrait;
use App\Models\BonusLogs;
use App\Models\Message;
use App\Models\NexusModel;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserMeta;
use App\Repositories\BonusRepository;
use App\Repositories\UserRepository;
use Carbon\Carbon;
use DateTime;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nexus\Database\NexusDB;
use Nexus\Nexus;
use Nexus\Plugin\BasePlugin;
use NexusPlugin\LuckyDraw\Filament\LuckyDrawResource;
use NexusPlugin\LuckyDraw\Filament\WinningRecordResource;
use NexusPlugin\LuckyDraw\Models\LuckyDrawPrize;
use Filament\Forms;
use NexusPlugin\LuckyDraw\Models\LuckyDrawWinningRecord;

class LuckyDrawRepository extends BasePlugin
{
    use OptionsTrait;

    const ACTION_NAME = '幸运大转盘';

    const CACHE_KEY = 'lucky_draw';

    const COMPATIBLE_VERSION = '1.7.21';

    public function install()
    {
        $this->runMigrations($this->getMigrationFilePath());
    }

    public function uninstall()
    {
        $this->runMigrations($this->getMigrationFilePath(), true);
    }

    public function boot()
    {
        $self = new self;
        $basePath = dirname(__DIR__);
        Nexus::addTranslationNamespace($basePath . '/resources/lang', 'lucky-draw');
//        add_action('nexus_register', [$self, 'actionRegisterFilamentResource'], 10, 1);
        add_filter('nexus_setting_tabs', [$self, 'filterAddSettingTab'], 10, 1);
        add_filter('nexus_home_module', [$self, 'filterRenderOnHomePage'], 10, 1);
    }

    private function getMigrationFilePath(): string
    {
        return dirname(__DIR__) . '/database/migrations';
    }

    public function getIsEnabled(): bool
    {
        return Setting::get('lucky_draw.enabled') == 'yes';
    }

    public function actionRegisterFilamentResource()
    {
        Filament::registerResources([LuckyDrawResource::class]);
        Livewire::component(get_filament_class_alias(LuckyDrawResource\Pages\ManageLuckyDraw::class), LuckyDrawResource\Pages\ManageLuckyDraw::class);
        Filament::registerResources([WinningRecordResource::class]);
        Livewire::component(get_filament_class_alias(WinningRecordResource\Pages\ManageWinningRecords::class), WinningRecordResource\Pages\ManageWinningRecords::class);
    }

    public function store(array $data)
    {
        $data = $this->formatData($data);
        return NexusDB::transaction(function () use ($data) {
            $prize = LuckyDrawPrize::query()->create($data);
            $this->updateSumProbability();
            return $prize;
        });
    }

    public function update(array $data, NexusModel $prize)
    {
        $data = $this->formatData($data);
        return NexusDB::transaction(function () use ($data, $prize) {
            $prize->update($data);
            $this->updateSumProbability();
            return $prize;
        });
    }

    public function delete(NexusModel $record)
    {
        return NexusDB::transaction(function () use ($record) {
            $record->delete();
            $this->updateSumProbability();
            return true;
        });
    }

    private function formatData(array $data): array
    {
        if (in_array($data['type'], LuckyDrawPrize::$constantAmountTypes)) {
            $data['amount'] = 1;
        }
        return array_filter($data);
    }

    private function updateSumProbability()
    {
        $sumProbability = LuckyDrawPrize::query()->sum('probability');
        NexusDB::cache_put($this->getSumProbabilityCacheKey(), $sumProbability,86400 * 365 * 100);
    }

    public function getSumProbability()
    {
        return  NexusDB::cache_get($this->getSumProbabilityCacheKey());
    }

    public function getProbabilityReal($probability): string
    {
        $sum = $this->getSumProbability();
        if ($sum <= 0) {
            return '-';
        }
        return number_format($probability / $this->getSumProbability(), 4);
    }

    private function getSumProbabilityCacheKey(): string
    {
        return self::CACHE_KEY . ":sumProbability";
    }

    public function listPrizes($withRealProbability = true): \Illuminate\Database\Eloquent\Collection|array
    {
        $prizes = LuckyDrawPrize::query()->orderBy('priority', 'desc')->get();
        if ($withRealProbability) {
            foreach ($prizes as &$prize) {
                $prize->probability_real = $this->getProbabilityReal($prize->probability);
            }
        }
        return $prizes;
    }

    public function winPrize()
    {
        $user = Auth::user();
        $lockKey = self::CACHE_KEY . ":running:" . $user->id;
        if (NexusDB::cache_get($lockKey)) {
            throw new \RuntimeException(__('lucky-draw::lucky-draw.click_duplicate'));
        }
        $requireBonus = Setting::get('lucky_draw.require_bonus');
        if ($user->seedbonus < $requireBonus) {
            throw new \RuntimeException(__('lucky-draw::lucky-draw.bonus_not_enough'));
        }
        $prizes = $this->listPrizes(false);
        $sum = $this->getSumProbability();
        if ($sum <= 0) {
            throw new \RuntimeException(__('lucky-draw::lucky-draw.config_error'));
        }
        $winPrize = $prizeIndex = null;
        // 先预处理一下
        // 先预处理一下
        if ($prizes[0]->type !== 1) {
            throw new \RuntimeException("Something-wrong-007");
        }
        // 先预处理一下
        // 先预处理一下
        foreach ($prizes as $index => $prize) {
            $probability = $prize->probability;
            $random = mt_rand(1, $sum);
            if ($random <= $probability) {
                $winPrize = $prize;
                $prizeIndex = $index;
                // ==============================看看有没有库存哈==============================
                // ==============================看看有没有库存哈==============================
                // ==============================看看有没有库存哈==============================
                $numDO = NexusDB::table("custom_lucky_draw_prizes_num")->where("id", $prize->id)->first();
                if ($numDO == null) {
                    // 没数据的数量无限
                    break;
                }
                // 每月刷新数据
                if (new DateTime($numDO->update_at) < new DateTime('first day of this month')) {
                    $updateMonthDO = [
                        "current_num"=> $numDO -> default_num,
                        "update_at"=>date("Y-m-d H:i:s")
                    ];
                    NexusDB::table("custom_lucky_draw_prizes_num")->where("id", $prize->id)->update($updateMonthDO);
                }
                // 重新查数量
                $numDO = NexusDB::table("custom_lucky_draw_prizes_num")->where("id", $prize->id)->first();
                if ($numDO -> current_num == 0) {
                    // 没有库存的情况
                    $winPrize = $prizes[0];
                    $prizeIndex = 0;
                    break;
                } else {
                    // 有, 库存-1
                    $updateDO = ["current_num"=>$numDO -> current_num-1];
                    NexusDB::table("custom_lucky_draw_prizes_num")->where("id", $prize->id)->update($updateDO);
                    break;
                }
                break;
                // ==============================看看有没有库存哈==============================
                // ==============================看看有没有库存哈==============================
                // ==============================看看有没有库存哈==============================
            } else {
                $sum -= $probability;
            }
        }
        if (!$winPrize) {
            throw new \RuntimeException("Something wrong!");
        }
        $result = $this->storeWinningRecord($user, $requireBonus, $prizeIndex, $prizes->count(), $winPrize);
        $duration = mt_rand(3000, 8000);//这是毫秒，缓存单位为秒
        NexusDB::cache_put($lockKey, $winPrize->id, floor($duration / 1000));
        $result['duration'] = $duration;
        return $result;
    }

    private function storeWinningRecord(User $user, $requireBonus, $index, $total, LuckyDrawPrize $prize)
    {
        do_log("[STORE_WINNING_RECORD], user: {$user->id}, require_bonus: $requireBonus, index: $index, total: $total ,prize: " . nexus_json_encode($prize->toArray()));
        $insert = [
            'uid' => $user->id,
            'cost_bonus' => $requireBonus,
            'prize_id' => $prize->id,
            'prize_type' => $prize->type,
            'prize_info' => $prize
        ];
        $winningRecord = NexusDB::transaction(function () use ($user, $insert, $prize, $requireBonus) {
            $bonusRep = new BonusRepository();
            $logComment = __('lucky-draw::lucky-draw.consume_bonus_comment', ['bonus' => $requireBonus, 'name' => __('lucky-draw::lucky-draw.label')]);
            //先消耗用户魔力
            $bonusRep->consumeUserBonus($user, $insert['cost_bonus'], BonusLogs::BUSINESS_TYPE_LUCKY_DRAW, $logComment);
            //再发放权益
            $this->issuanceBenefits($user, $prize);
            //最后创建中奖记录
            return LuckyDrawWinningRecord::query()->create($insert);
        });
        $circleNum = mt_rand(5, 10);
        $angleBase = $circleNum * 360;
        $angleStep = 360 / $total;
        $start = -$angleStep / 2;
        $angleOffsetMin = ceil($angleStep * $index);
        $angleOffsetMax = floor($angleOffsetMin + $angleStep);
        $angleOffset = mt_rand($angleOffsetMin, $angleOffsetMax);
        return [
            'angle' => 360 - ($angleOffset + $start) + $angleBase,
            'prize_text' => sprintf('%s %s', $prize->typeText, $prize->amountText),
            'easing' => $this->getAnimation(),
            'winning_record_id' => $winningRecord->id,
        ];
    }

    private function getAnimation(): string
    {
        $inOut = ['In', 'Out', 'InOut'];
        $types = ['Quad', 'Cubic', 'Quart', 'Quint', 'Sine', 'Expo', 'Circ', 'Elastic', 'Back', 'Bounce'];
        if (mt_rand(1, 100) == 1) {
            return 'jswing';
        }
        return sprintf('ease%s%s', $inOut[mt_rand(0, count($inOut) - 1)], $types[mt_rand(0, count($types) - 1)]);
    }

    public function filterAddSettingTab(array $tabs): array
    {
        $tabs[] = Forms\Components\Tabs\Tab::make(__('lucky-draw::lucky-draw.label'))
            ->id('lucky_draw')
            ->schema([
                Forms\Components\Radio::make('lucky_draw.enabled')->options(self::$yesOrNo)->inline(true)->label(__('label.enabled')),
                Forms\Components\TextInput::make('lucky_draw.require_bonus')->label(__('lucky-draw::lucky-draw.setting.require_bonus'))->integer(),
                Forms\Components\TextInput::make('lucky_draw.vip_nullify_reward_bonus')->label(__('lucky-draw::lucky-draw.setting.vip_nullify_reward_bonus'))->integer(),
                Forms\Components\Textarea::make('lucky_draw.extra_description')->label(__('lucky-draw::lucky-draw.setting.extra_description')),
            ])->columns(2);

        return $tabs;
    }

    private function issuanceBenefits(User $user, LuckyDrawPrize $prize)
    {
        do_log("[ISSUANCE_BENEFITS], user: {$user->id}, prize: {$prize->id}");
        NexusDB::transaction(function () use ($user, $prize) {
            match ($prize->type) {
                LuckyDrawPrize::TYPE_THANKS => $this->issuanceBenefitThanks($user, $prize),

                LuckyDrawPrize::TYPE_PERSONALIZED_USERNAME => $this->issuanceBenefitPersonalizedUsername($user, $prize),
                LuckyDrawPrize::TYPE_CHANGE_USERNAME => $this->issuanceBenefitAddUserMeta($user, ['meta_key' => UserMeta::META_KEY_CHANGE_USERNAME, ], ['updated_at' => NexusDB::raw('now()')]),

                LuckyDrawPrize::TYPE_BONUS => $this->issuanceBenefitBonus($user, $prize),
                LuckyDrawPrize::TYPE_UPLOADED => $this->issuanceBenefitIncreaseUserField($user, $prize, 'uploaded', $prize->amount * 1024 * 1024 * 1024),
                LuckyDrawPrize::TYPE_ATTENDANCE_CARD => $this->issuanceBenefitIncreaseUserField($user, $prize, 'attendance_card', $prize->amount),
                LuckyDrawPrize::TYPE_INVITE => $this->issuanceBenefitIncreaseUserField($user, $prize, 'invites', $prize->amount),

                LuckyDrawPrize::TYPE_VIP => $this->issuanceBenefitVIP($user, $prize),

                default => throw new \RuntimeException("Invalid prize type: " . $prize->type)

            };
            if ($prize->type != LuckyDrawPrize::TYPE_THANKS) {
                $this->insertWinPrizeMessage($user, $prize);
            }
        });

    }

    private function issuanceBenefitThanks(User $user, LuckyDrawPrize $prize)
    {
        do_log("Thanks");
    }

    private function issuanceBenefitPersonalizedUsername(User $user, LuckyDrawPrize $prize)
    {
        $metaKey = UserMeta::META_KEY_PERSONALIZED_USERNAME;
        $days = $prize->amount;
        $meta = $user->metas()->where('meta_key', $metaKey)->first();
        if (!$meta) {
            //No record, just insert one
            $insert = [
                'meta_key' => $metaKey,
                'deadline' => now()->addDays($days),
            ];
            $user->metas()->create($insert);
        } else {
            if ($meta->deadline) {
                //Not permanently, update deadline
                if ($meta->deadline->lte(now())) {
                    $deadline = now()->addDays($days);
                } else {
                    $deadline = $meta->deadline->addDays($days);
                }
                $meta->deadline = $deadline;
            }
            $meta->status = UserMeta::STATUS_NORMAL;
            $meta->save();
        }
        clear_user_cache($user->id);
    }

    private function issuanceBenefitAddUserMeta(User $user, array $metaData, array $keyExistsUpdates = [])
    {
        $userRep = new UserRepository();
        $userRep->addMeta($user, $metaData, $keyExistsUpdates);
    }

    private function issuanceBenefitIncreaseUserField(User $user, LuckyDrawPrize $prize, $field, $value)
    {
        $user->update([$field => NexusDB::raw("$field + $value")]);
        clear_user_cache($user->id, $user->passkey);
    }

    private function issuanceBenefitBonus(User $user, LuckyDrawPrize $prize)
    {
        $comment = __('lucky-draw::lucky-draw.issuance_benefit_body.' . LuckyDrawPrize::TYPE_BONUS, [
            'name' => __('lucky-draw::lucky-draw.label'),
            'type_text' => $prize->typeText,
            'amount_text' => $prize->amountText
        ]);
        $user->updateWithComment([
            'seedbonus' => NexusDB::raw("seedbonus + " . $prize->amount),
        ], date('Y-m-d') . " $comment", "bonuscomment");
    }

    private function issuanceBenefitVIP(User $user, LuckyDrawPrize $prize)
    {
        if ($user->class >= User::CLASS_VIP) {
            do_log("already VIP or above.");
            $rewardBonus = Setting::get('lucky_draw.vip_nullify_reward_bonus');
            $comment = __('lucky-draw::lucky-draw.issuance_benefit_body_vip_nullify', ['reward_bonus' => $rewardBonus]);
            $user->updateWithComment([
                'seedbonus' => NexusDB::raw("seedbonus + " . $rewardBonus),
            ], date('Y-m-d') . " $comment", "bonuscomment");
        } else {
            $update = [
                'class' => User::CLASS_VIP,
                'vip_added' => 'yes',
                'vip_until' => now()->addDays($prize->amount)
            ];
            $user->update($update);
            clear_user_cache($user->id, $user->passkey);
        }
    }

    private function insertWinPrizeMessage(User $user, LuckyDrawPrize $prize)
    {
        if ($user->class >= User::CLASS_VIP && $prize->type == LuckyDrawPrize::TYPE_VIP) {
            $rewardBonus = Setting::get('lucky_draw.vip_nullify_reward_bonus');
            $body = __('lucky-draw::lucky-draw.issuance_benefit_body_vip_nullify', ['reward_bonus' => $rewardBonus]);
        } else {
            $body = __('lucky-draw::lucky-draw.issuance_benefit_body.' . $prize->type, [
                'name' => __('lucky-draw::lucky-draw.label'),
                'type_text' => $prize->typeText,
                'amount_text' => $prize->amountText
            ]);
        }
        $msg = [
            'receiver' => $user->id,
            'added' => now(),
            'subject' => __('lucky-draw::lucky-draw.issuance_benefit_subject', ['name' => __('lucky-draw::lucky-draw.label')]),
            'msg' => $body,
        ];
        Message::add($msg);
    }

    public function filterRenderOnHomePage(array $modules): array
    {
        if (!$this->getIsEnabled()) {
            return $modules;
        }
        $records = LuckyDrawWinningRecord::query()
            ->where('prize_type', '!=', LuckyDrawPrize::TYPE_THANKS)
            ->orderBy('id', 'desc')
            ->take(15)
            ->get();
        $html = sprintf('<h2>%s<font class="small"> - [<a class="altlink" href="/plugin/lucky-draw" target="_blank"><b>%s</b></a>]</font></h2>', nexus_trans('lucky-draw::lucky-draw.label'), nexus_trans('lucky-draw::lucky-draw.home_display.go'));
        $html .= '<table width="100%" border="0" cellpadding="5">';
        $html .= '<tr><td width="100%">';
        foreach ($records as $record) {
            $trContent = nexus_trans('lucky-draw::lucky-draw.home_display.tr_content', [
                'time' => format_datetime($record->created_at),
                'username' => get_username($record->uid),
                'type_text' => nexus_trans('lucky-draw::lucky-draw.type.' . $record->prize_info->type),
                'amount_text' => $record->prize_info->amountText,
            ]);
            $html .= "<div>$trContent</div>";
        }
        $html .= '</td></tr>';
        $html .= "</table>";
        $modules[] = $html;
        return $modules;
    }

}

