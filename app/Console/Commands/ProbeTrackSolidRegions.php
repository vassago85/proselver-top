<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\TrackSolid\Client as TrackSolidClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Throwaway diagnostic command: tries `jimi.oauth.token.get` against every
 * known TrackSolid datacentre with the configured creds (or with whatever
 * creds the operator supplies on the command line) so we can pinpoint:
 *
 *   - which region the appKey is registered against (HK vs EU vs US)
 *   - whether the password is being md5'd correctly
 *   - whether the appKey has been activated by TrackSolid yet
 *
 * Usage on the server:
 *
 *   sudo docker compose exec -T app php artisan tracksolid:probe
 *
 * Or override creds without saving anything:
 *
 *   sudo docker compose exec -T app php artisan tracksolid:probe \
 *     --account=JMTEST123 \
 *     --password-md5=21218cca77804d2ba1922c33e0151105 \
 *     --app-key=8FB345B8693CCD00CE073CAB5F094009339A22A4105B6558 \
 *     --app-secret=c0aa0226fddc4365a3c67fef45427f8a
 *
 * Each region prints HTTP status + first 200 chars of body, so you'll see
 * "code 0 success" for the right region and "code 1001 invalid AppKey"
 * everywhere else.
 */
class ProbeTrackSolidRegions extends Command
{
    protected $signature = 'tracksolid:probe
                            {--account= : Override the configured account / user_id}
                            {--password= : Plain-text password (we will md5 it)}
                            {--password-md5= : Pre-computed lowercase md5 of the password}
                            {--app-key= : Override the configured app key}
                            {--app-secret= : Override the configured app secret}';

    protected $description = 'Try jimi.oauth.token.get against every TrackSolid datacentre to find the right region for an appKey.';

    /**
     * Datacentre map sourced from §6 of the TrackSolid Pro v2.7.14 spec
     * plus the legacy `open.10000track.com` host that some older sandbox
     * keys are still registered against.
     */
    private const REGIONS = [
        'TS legacy (open.10000track.com)' => 'http://open.10000track.com',
        'TSP HK (hk-open.tracksolidpro.com)' => 'https://hk-open.tracksolidpro.com',
        'TSP EU (eu-open.tracksolidpro.com)' => 'https://eu-open.tracksolidpro.com',
        'TSP US (us-open.tracksolidpro.com)' => 'https://us-open.tracksolidpro.com',
    ];

    public function handle(): int
    {
        $appKey = (string) ($this->option('app-key')
            ?: SystemSetting::get(TrackSolidClient::SETTING_APP_KEY, ''));
        $appSecret = (string) ($this->option('app-secret')
            ?: SystemSetting::get(TrackSolidClient::SETTING_APP_SECRET, ''));
        $account = (string) ($this->option('account')
            ?: SystemSetting::get(TrackSolidClient::SETTING_ACCOUNT, ''));

        $pwdMd5 = (string) $this->option('password-md5');
        if ($pwdMd5 === '' && $this->option('password')) {
            $pwdMd5 = md5((string) $this->option('password'));
        }
        if ($pwdMd5 === '') {
            $pwdMd5 = (string) SystemSetting::get(TrackSolidClient::SETTING_USER_PWD_MD5, '');
        }

        if ($appKey === '' || $appSecret === '' || $account === '' || $pwdMd5 === '') {
            $this->error('Missing creds. Either configure them in /admin/settings/integrations OR pass --account / --password / --app-key / --app-secret on the command line.');
            return self::FAILURE;
        }

        $this->line(sprintf(
            'Probing with appKey=%s... account=%s pwd_md5=%s...',
            substr($appKey, 0, 8),
            $account,
            substr($pwdMd5, 0, 8)
        ));
        $this->newLine();

        foreach (self::REGIONS as $label => $base) {
            $params = [
                'method' => 'jimi.oauth.token.get',
                'app_key' => $appKey,
                'sign_method' => 'md5',
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'v' => '1.0',
                'format' => 'json',
                'user_id' => $account,
                'user_pwd_md5' => $pwdMd5,
                'expires_in' => '7200',
            ];
            $params['sign'] = TrackSolidClient::buildSignature($params, $appSecret);

            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout(15)
                    ->post(rtrim($base, '/') . '/route/rest', $params);

                $status = $response->status();
                $body = mb_substr(trim($response->body()), 0, 200);
                $code = $response->json('code');

                $verdict = $code === 0 ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
                $this->line(sprintf('  %s  %s  HTTP %d  code=%s  %s', $verdict, $label, $status, $code ?? '?', $body));
            } catch (\Throwable $e) {
                $this->line(sprintf('  <fg=red>ERR</>   %s  %s', $label, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->line('Look for a row with code=0; that is the region your appKey is registered against. Set the Base URL in /admin/settings/integrations to match and save.');

        return self::SUCCESS;
    }
}
