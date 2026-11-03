<?php
declare(strict_types=1);
namespace WaBridge\Sdk;

/** Dependency-free client for WaBridge's scoped REST API. PHP >= 8.2. */
final class Client {
    public function __construct(private string $baseUrl, private string $apiKey) {
        if (!preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::443)?(?:/[a-zA-Z0-9_/-]*)?\z~D',rtrim($baseUrl,'/')))
            throw new \InvalidArgumentException('HTTPS API base URL required');
        if (strlen($apiKey)<24) throw new \InvalidArgumentException('Invalid API key');
    }
    public function sendText(string $sessionId,string $recipient,string $text,string $idempotencyKey): array {
        return $this->request('POST','/api/v1/messages.php',['session_id'=>$sessionId,'to'=>$recipient,'text'=>$text],$idempotencyKey);
    }
    public function status(string $jobId): array {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$jobId)) throw new \InvalidArgumentException('Invalid job ID');
        return $this->request('GET','/api/v1/jobs.php?id='.$jobId);
    }
    public function devices(): array { return $this->request('GET','/api/v1/devices.php'); }
    public function capabilities(): array { return $this->request('GET','/api/v1/capabilities.php'); }
    private function request(string $method,string $path,?array $json=null,?string $idem=null): array {
        $ch=curl_init(rtrim($this->baseUrl,'/').$path);
        if (!$ch) throw new \RuntimeException('cURL unavailable');
        $headers=['Authorization: Bearer '.$this->apiKey,'Accept: application/json'];
        if ($json!==null) $headers[]='Content-Type: application/json';
        if ($idem!==null) $headers[]='Idempotency-Key: '.$idem;
        curl_setopt_array($ch,[
            CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        ]);
        if ($json!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($json,JSON_THROW_ON_ERROR));
        $raw=curl_exec($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw)) throw new \RuntimeException('Request failed');
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if ($code<200 || $code>=300) throw new \RuntimeException('WaBridge HTTP '.$code);
        return is_array($data)?$data:[];
    }
}
