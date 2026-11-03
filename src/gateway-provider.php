<?php
declare(strict_types=1);

/**
 * Waxum provider boundary. Keep all upstream-specific response normalization here.
 * PHP must never infer "connected" from a merely running Rust HTTP listener.
 */
function gateway_diagnostics(): array {
    $c = config();
    $url=(string)($c['gateway_url']??'');
    $token=(string)($c['gateway_token']??'');
    if ($token==='') return ['state'=>'not_configured','ready'=>false,'message'=>'Rust gateway token has not been configured.','http'=>null];
    if ($url==='') return ['state'=>'not_configured','ready'=>false,'message'=>'Rust gateway URL has not been configured.','http'=>null];
    try {
        $live=gateway('GET','/livez');
        if ($live['http']!==200) return ['state'=>'unreachable','ready'=>false,'message'=>'Rust gateway is unreachable or its liveness probe failed.','http'=>$live['http']];
        $ready=gateway('GET','/readyz');
        if ($ready['http']===200) return ['state'=>'ready','ready'=>true,'message'=>'Rust process and storage readiness confirmed. WhatsApp sessions require separate checks.','http'=>200];
        return ['state'=>'not_ready','ready'=>false,'message'=>'Rust is responding but its readiness check failed. Verify SQLite and engine logs.','http'=>$ready['http']];
    } catch (Throwable $e) {
        error_log('WaBridge gateway diagnostics '.get_class($e));
        return ['state'=>'invalid_configuration','ready'=>false,'message'=>'Gateway URL or transport configuration invalid.','http'=>null];
    }
}
function upstream_sessions(): array {
    $r=gateway('GET','/api/v1/sessions');
    if ($r['http']!==200) return [];
    $data=$r['data']??[];
    $sessions=is_array($data) && array_is_list($data) ? $data : ($data['sessions']??[]);
    return is_array($sessions) ? array_values(array_filter($sessions,'is_array')) : [];
}
function session_state(string $sid): array {
    if (!valid_session_id($sid)) throw new InvalidArgumentException('Invalid session');
    $r=gateway('GET','/api/v1/sessions/'.$sid.'/status');
    if ($r['http']!==200) return ['status'=>'unavailable','is_logged_in'=>false,'http'=>$r['http']];
    return [
        'status'=>(string)($r['data']['status']??'unknown'),
        'is_logged_in'=>(bool)($r['data']['is_logged_in']??false),
        'socket_alive'=>(bool)($r['data']['socket_alive']??false),
        'phone_number'=>$r['data']['phone_number']??null,
        'http'=>200,
    ];
}
function engine_capabilities(): array {
    return [
        'sessions'=>['enabled'=>true,'route'=>'/api/v1/sessions','verified_in_upstream_source'=>true],
        'qr_json'=>['enabled'=>true,'route'=>'/api/v1/sessions/{id}/qr','verified_in_upstream_source'=>true],
        'status'=>['enabled'=>true,'route'=>'/api/v1/sessions/{id}/status','verified_in_upstream_source'=>true],
        'text'=>['enabled'=>true,'route'=>'/api/v1/sessions/{id}/messages/text','verified_in_upstream_source'=>true],
        'image'=>['enabled'=>true,'route'=>'/api/v1/sessions/{id}/messages/image','verified_in_upstream_source'=>true],
        'document'=>['enabled'=>true,'route'=>'/api/v1/sessions/{id}/messages/document','verified_in_upstream_source'=>true],
        'audio'=>['enabled'=>true,'route'=>'/api/v1/sessions/{id}/messages/audio','verified_in_upstream_source'=>true],
        'video'=>['enabled'=>true,'route'=>'/api/v1/sessions/{id}/messages/video','verified_in_upstream_source'=>true],
        'contacts'=>['enabled'=>false,'reason'=>'WaBridge adapter and authorization checks pending'],
        'groups'=>['enabled'=>false,'reason'=>'WaBridge adapter and authorization checks pending'],
        'labels'=>['enabled'=>false,'reason'=>'Not verified supported by configured Rust provider'],
        'channels'=>['enabled'=>false,'reason'=>'Not verified supported by configured Rust provider'],
        'otp'=>['enabled'=>false,'reason'=>'Unofficial delivery must not be sole authentication channel'],
        'bulk'=>['enabled'=>false,'reason'=>'Consent enforcement and abuse controls required'],
    ];
}
