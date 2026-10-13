<?php
namespace App\routes;

use App\Auth;
use App\Config;
use App\Db;
use App\Http;
use App\HttpException;
use App\Log;
use App\RateLimit;
use App\Settings;
use App\Time;
use App\util\Validate;
use App\WsOutbox;

/**
 * 登录与账号相关接口（API.md §2）
 */
class AuthRoutes
{
    /** bcrypt 轮数（与 Node 版一致：10 轮） */
    const BCRYPT_ROUNDS = 10;

    public static function register($r)
    {
        // 登录/注册限流：同一 IP 15 分钟内最多 30 次
        $limiter = function () {
            RateLimit::enforce('auth:' . Http::clientIp(), 30, 900000, '登录尝试过于频繁，请稍后再试');
        };

        // POST /api/auth/register
        $r->post('/api/auth/register', function () use ($limiter) {
            $limiter();
            $body = Http::jsonBody();

            // 注册开关（registerOpen=false 时返回 403 forbidden）
            if (!Settings::isRegisterOpen()) {
                throw new HttpException(403, 'forbidden', '当前已关闭注册');
            }

            $username = Validate::validateUsername(Http::field($body, 'username'));
            $password = Validate::validatePassword(Http::field($body, 'password'));
            $nicknameRaw = Http::field($body, 'nickname');
            $nickname = ($nicknameRaw === null || $nicknameRaw === '')
                ? $username
                : Validate::validateNickname($nicknameRaw);
            $avatarEmoji = Validate::validateAvatarEmoji(Http::field($body, 'avatarEmoji'));
            if ($avatarEmoji === null) {
                $avatarEmoji = '';
            }

            // 用户名唯一性
            if (Db::findUserByUsername($username)) {
                throw new HttpException(409, 'conflict', '该用户名已被注册');
            }

            // 可选邀请码：填了就直接加入对方情侣关系
            $inviteTarget = null;
            $inviteRaw = Http::field($body, 'inviteCode');
            if ($inviteRaw !== null && $inviteRaw !== '') {
                $code = strtoupper(Validate::requireString($inviteRaw, 'inviteCode', [
                    'min' => 4, 'max' => 16, 'trim' => true,
                ]));
                $inviteTarget = Db::getCoupleByInviteCode($code);
                if (!$inviteTarget) {
                    throw new HttpException(404, 'not_found', '邀请码无效');
                }
                if (count($inviteTarget['members']) >= Config::limit('COUPLE_MAX_MEMBERS')) {
                    throw new HttpException(409, 'conflict', '该情侣空间已满');
                }
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_ROUNDS]);
            $created = Db::createUser([
                'username' => $username,
                'passwordHash' => $hash,
                'nickname' => $nickname,
                'avatarEmoji' => $avatarEmoji,
            ]);

            if ($inviteTarget) {
                Db::setUserCouple($created['user']['id'], $inviteTarget['id']);
            }

            Db::touchUserLastSeen($created['user']['id']);
            Log::info('用户注册成功', ['userId' => $created['user']['id'], 'joined' => $inviteTarget !== null]);

            $fresh = Db::getUserById($created['user']['id']);
            $user = $fresh['user'];
            $couple = $user['coupleId'] ? Db::buildCouple($user['coupleId']) : null;
            Http::ok([
                'token' => Auth::signUserToken($fresh['row']),
                'user' => $user,
                'couple' => $couple,
            ]);
        });

        // POST /api/auth/login
        $r->post('/api/auth/login', function () use ($limiter) {
            $limiter();
            $body = Http::jsonBody();
            $username = Validate::requireString(Http::field($body, 'username'), 'username', [
                'min' => 1, 'max' => 32, 'trim' => true,
            ]);
            $password = Validate::requireString(Http::field($body, 'password'), 'password', [
                'min' => 1, 'max' => 64,
            ]);

            $found = Db::findUserByUsername($username);
            // 统一提示，避免用户名枚举
            if (!$found || !password_verify($password, $found['row']['password_hash'])) {
                throw new HttpException(401, 'unauthorized', '用户名或密码错误');
            }
            if (!empty($found['row']['disabled'])) {
                throw new HttpException(403, 'forbidden', '账号已被禁用，请联系管理员');
            }

            Db::touchUserLastSeen($found['user']['id']);
            $fresh = Db::getUserById($found['user']['id']);
            $user = $fresh['user'];
            $couple = $user['coupleId'] ? Db::buildCouple($user['coupleId']) : null;
            Http::ok([
                'token' => Auth::signUserToken($fresh['row']),
                'user' => $user,
                'couple' => $couple,
            ]);
        });

        // GET /api/auth/me
        $r->get('/api/auth/me', function () {
            $found = Auth::requireUser();
            $fresh = Db::getUserById($found['user']['id']);
            if (!$fresh) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            $couple = $fresh['user']['coupleId'] ? Db::buildCouple($fresh['user']['coupleId']) : null;
            Http::ok(['user' => $fresh['user'], 'couple' => $couple]);
        });

        // PATCH /api/auth/me
        $r->patch('/api/auth/me', function () {
            $me = Auth::requireUser();
            $body = Http::jsonBody();
            if (!Http::has($body, 'nickname') && !Http::has($body, 'avatarEmoji')) {
                Validate::fail('请至少提供 nickname 或 avatarEmoji');
            }
            $patch = [];
            if (Http::has($body, 'nickname')) {
                $patch['nickname'] = Validate::validateNickname(Http::field($body, 'nickname'));
            }
            if (Http::has($body, 'avatarEmoji')) {
                $emoji = Validate::validateAvatarEmoji(Http::field($body, 'avatarEmoji'));
                $patch['avatarEmoji'] = $emoji === null ? '' : $emoji;
            }
            $updated = Db::updateUserProfile($me['user']['id'], $patch);
            if (!$updated) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            Http::json(['user' => $updated['user'], 'serverTime' => Time::nowMs()], 200);
        });

        // POST /api/auth/password
        $r->post('/api/auth/password', function () {
            $me = Auth::requireUser();
            $body = Http::jsonBody();
            $oldPassword = Validate::requireString(Http::field($body, 'oldPassword'), 'oldPassword', [
                'min' => 1, 'max' => 64,
            ]);
            $newPassword = Validate::validatePassword(Http::field($body, 'newPassword'), 'newPassword');

            $fresh = Db::getUserById($me['user']['id']);
            if (!$fresh) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            if (!password_verify($oldPassword, $fresh['row']['password_hash'])) {
                throw new HttpException(401, 'unauthorized', '原密码错误');
            }
            $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_ROUNDS]);
            Db::updateUserPassword($me['user']['id'], $hash);
            Log::info('用户修改密码', ['userId' => $me['user']['id']]);
            Http::ok(['ok' => true]);
        });
    }
}
