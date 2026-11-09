<?php
declare(strict_types=1);

/**
 * Safe, deterministic UI fixture. It never reads application messages,
 * credentials, contacts, provider sessions, or writes runtime data.
 */
final class VNextDemo {
    public static function accounts(): array {
        return [
            ['id'=>'8aab4000-0000-4000-8000-000000000001',
             'name'=>'Demo · Acme Admissions','industry'=>'Education',
             'users'=>4,'channels'=>2,'status'=>'Simulation only','workload'=>'Sample enquiries'],
            ['id'=>'8aab4000-0000-4000-8000-000000000002',
             'name'=>'Demo · Greenfield Retail','industry'=>'Retail',
             'users'=>3,'channels'=>1,'status'=>'Simulation only','workload'=>'Sample order updates'],
            ['id'=>'8aab4000-0000-4000-8000-000000000003',
             'name'=>'Demo · Regional Support','industry'=>'Services',
             'users'=>5,'channels'=>1,'status'=>'Simulation only','workload'=>'Sample support cases'],
        ];
    }

    public static function overview(): array {
        $accounts=self::accounts();
        return ['accounts'=>$accounts,'tenant_count'=>count($accounts),
            'user_count'=>array_sum(array_column($accounts,'users')),
            'channel_count'=>array_sum(array_column($accounts,'channels')),
            'is_synthetic'=>true,'live_sends_enabled'=>false];
    }
}
