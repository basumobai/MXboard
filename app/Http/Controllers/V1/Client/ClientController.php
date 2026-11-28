<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag');
        $flag = strtolower(is_string($flag) ? $flag : ($request->userAgent() ?? ''));
        $user = $request->user;
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);

            if ($flag !== '') {
                if (strpos($flag, 'sing') !== false) {
                    $protocolServers = $this->filterServersForFlag($servers, 'sing');
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if ($version !== null && version_compare($version, '1.12.0', '>=')) {
                        $protocolClass = Singbox::class;
                        $protocolServers = $this->applyPinnedTlsFallback($protocolServers, $protocolClass);
                        return (new Singbox($user, $protocolServers, ['version' => $version]))->handle();
                    }
                    $protocolClass = SingboxOld::class;
                    $protocolServers = $this->applyPinnedTlsFallback($protocolServers, $protocolClass);
                    return (new SingboxOld($user, $protocolServers))->handle();
                }

                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $file = 'App\\Protocols\\' . basename($file, '.php');
                    $protocol = new $file($user, $servers);
                    if (strpos($flag, $protocol->flag) !== false) {
                        $protocolServers = $this->filterServersForFlag($servers, $protocol->flag);
                        $this->setSubscribeInfoToServers($protocolServers, $user);
                        $protocolServers = $this->applyPinnedTlsFallback($protocolServers, $file);
                        return (new $file($user, $protocolServers))->handle();
                    }
                }
            }

            return (new General($user, $servers))->handle();
        }
    }

    private function filterServersForFlag(array $servers, string $flag): array
    {
        if ($this->shouldExposeMxAndMc1($flag)) {
            return $servers;
        }

        return array_values(array_filter($servers, function ($server) {
            if (($server['type'] ?? null) === 'mx') {
                return false;
            }
            if (in_array(($server['network'] ?? null), ['mc1', 'mundordp'], true)) {
                return false;
            }
            if (($server['type'] ?? null) !== 'v2node') {
                return true;
            }
            if (($server['protocol'] ?? null) === 'mx') {
                return false;
            }
            return ($server['network'] ?? null) !== 'mc1';
        }));
    }

    private function applyPinnedTlsFallback(array $servers, string $protocolClass): array
    {
        $allowInsecureWithoutPin = in_array($protocolClass, [
            \App\Protocols\Clash::class,
            \App\Protocols\ClashMeta::class,
            \App\Protocols\ClashNyanpasu::class,
            \App\Protocols\ClashVerge::class,
            Singbox::class,
            SingboxOld::class,
        ], true);

        foreach ($servers as $key => $server) {
            $type = ($server['type'] ?? null) === 'v2node'
                ? ($server['protocol'] ?? null)
                : ($server['type'] ?? null);
            if ($type === 'shadowsocks' || (int) ($server['tls'] ?? 1) !== 1) {
                continue;
            }

            $tlsSettings = $server['tls_settings'] ?? $server['tlsSettings'] ?? [];
            if (!is_array($tlsSettings)) {
                continue;
            }
            $pin = $tlsSettings['pinned_peer_cert_sha256'] ?? '';
            $hasPin = is_string($pin) && trim($pin) !== '';
            $remoteTls = ($server['type'] ?? null) === 'v2node'
                && ($tlsSettings['cert_mode'] ?? null) === 'remote';
            if (!$hasPin && !($allowInsecureWithoutPin && $remoteTls)) {
                continue;
            }

            $fingerprint = Helper::getTlsCertificateFingerprint($server);
            if ($fingerprint !== null) {
                $tlsSettings['pinned_peer_cert_sha256'] = $fingerprint;
            }
            $tlsSettings['allow_insecure'] = 1;
            if ($type === 'vmess') {
                $tlsSettings['allowInsecure'] = 1;
            }
            $servers[$key]['tls_settings'] = $tlsSettings;
            foreach (['allow_insecure', 'insecure'] as $flagName) {
                if (array_key_exists($flagName, $server)) {
                    $servers[$key][$flagName] = 1;
                }
            }
        }

        return $servers;
    }

    private function shouldExposeMxAndMc1(string $flag): bool
    {
        return in_array($flag, [
            'general',
            'passwall',
            'sagernet',
            'ssrplus',
            'v2rayn',
            'v2rayng',
            'v2raytun',
        ], true);
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
