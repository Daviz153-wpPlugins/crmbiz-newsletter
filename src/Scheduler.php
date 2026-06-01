<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

/**
 * Action Scheduler가 있으면 사용, 없으면 WP Cron으로 폴백.
 * 호출부는 이 클래스만 알면 되고 외부 의존성을 신경 쓸 필요 없음.
 */
class Scheduler {

    private const GROUP = 'crmbiz-newsletter';

    public static function scheduleSingle(int $timestamp, string $hook, array $args = []): void {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, $hook, $args, self::GROUP);
        } else {
            wp_schedule_single_event($timestamp, $hook, $args);
        }
    }

    public static function unschedule(string $hook, array $args = []): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, $args, self::GROUP);
        } else {
            wp_clear_scheduled_hook($hook, $args);
        }
    }

    public static function isScheduled(string $hook, array $args = []): bool {
        if (function_exists('as_next_scheduled_action')) {
            return (bool) as_next_scheduled_action($hook, $args, self::GROUP);
        }
        return (bool) wp_next_scheduled($hook, $args);
    }

    /**
     * args에 관계없이 해당 훅의 모든 예약 액션을 취소.
     * 플러그인 비활성화 시 사용 — 개별 args 조합 없이 일괄 정리 필요한 경우.
     */
    public static function unscheduleAll(string $hook): void {
        if (function_exists('as_unschedule_all_actions')) {
            // null = args 무관하게 해당 훅의 모든 액션 취소
            as_unschedule_all_actions($hook, null, self::GROUP);
        } else {
            wp_clear_scheduled_hook($hook);
        }
    }
}
