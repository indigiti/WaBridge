<?php
declare(strict_types=1);
require_once __DIR__.'/json-store.php';

/**
 * W1 single-document backup, hash verification and compare-and-swap restore.
 * No automatic recovery, network paths, or cross-file atomic promises.
 */
final class VNextBackup {
    private const TYPES=['inbox','studio','events','projections','intents'];
    private string $directory;

    public function __construct(private VNextJsonStore $store,string $privateRoot) {
        $base=rtrim($privateRoot,'/');
        if($base===''||is_link($base)||!is_dir($base)) {
            throw new InvalidArgumentException('Private root must exist and not be a link');
        }
        $this->directory=$base.'/backups';
        if(is_link($this->directory))throw new RuntimeException('Backup symlink forbidden');
        if(!is_dir($this->directory)
            && !mkdir($this->directory,0700)
            && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create backup directory');
        }
        @chmod($this->directory,0700);
    }

    private static function validate(string $tenant,string $collection):void {
        if(!VNextJsonStore::uuid($tenant)||!in_array($collection,self::TYPES,true)) {
            throw new InvalidArgumentException('Only tenant-scoped JSON backup supported');
        }
    }

    public function create(string $tenant,string $collection):array {
        self::validate($tenant,$collection);
        $snapshot=$this->store->read($tenant,$collection,
            static fn(array $items,int $revision):array => [
                'schema'=>'WABRIDGE-VNEXT-SNAPSHOT/1',
                'tenant'=>$tenant,'collection'=>$collection,
                'revision'=>$revision,'items'=>$items,
            ]);
        $canonical=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($canonical)>2100000)throw new RuntimeException('Backup size limit');
        $wrap=['payload'=>$snapshot,'sha256'=>hash('sha256',$canonical),
            'created_at'=>gmdate('c')];
        $encoded=json_encode($wrap,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $id=bin2hex(random_bytes(16));
        $dest=$this->directory.'/'.$id.'.json';
        $file=fopen($dest,'x+b');
        if($file===false)throw new RuntimeException('Backup filename already exists');
        try {
            if(!chmod($dest,0600))throw new RuntimeException('Cannot protect backup');
            $total=strlen($encoded);$written=0;
            while($written<$total) {
                $n=fwrite($file,substr($encoded,$written));
                if($n===false||$n===0)throw new RuntimeException('Incomplete snapshot');
                $written+=$n;
            }
            if(!fflush($file))throw new RuntimeException('Snapshot flush failed');
            if(function_exists('fsync')&&!fsync($file))throw new RuntimeException('Snapshot sync failed');
        }catch(Throwable $e){
            fclose($file);@unlink($dest);throw $e;
        }
        fclose($file);
        return ['id'=>$id,'sha256'=>$wrap['sha256'],
            'revision'=>$snapshot['revision'],'scope'=>$tenant,'collection'=>$collection];
    }

    private function read(string $id):array {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)) {
            throw new InvalidArgumentException('Invalid backup ID');
        }
        $path=$this->directory.'/'.$id.'.json';
        if(is_link($path)||!is_file($path))throw new RuntimeException('Backup not found');
        $size=filesize($path);
        if($size===false||$size<1||$size>2200000)throw new RuntimeException('Invalid backup size');
        $row=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($row)||!is_array($row['payload']??null)
            ||!is_string($row['sha256']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])) {
            throw new RuntimeException('Invalid backup manifest');
        }
        $p=$row['payload'];
        if(($p['schema']??'')!=='WABRIDGE-VNEXT-SNAPSHOT/1'
            ||!is_string($p['tenant']??null)
            ||!is_string($p['collection']??null)
            ||!is_int($p['revision']??null)
            ||$p['revision']<0
            ||!is_array($p['items']??null)) {
            throw new RuntimeException('Invalid backup schema');
        }
        self::validate($p['tenant'],$p['collection']);
        $body=json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(!hash_equals($row['sha256'],hash('sha256',$body))) {
            throw new RuntimeException('Backup checksum mismatch');
        }
        return $row;
    }

    public function verify(string $id):array {
        $b=$this->read($id);
        return ['valid'=>true,'tenant'=>$b['payload']['tenant'],
            'collection'=>$b['payload']['collection'],
            'revision'=>$b['payload']['revision'],
            'sha256'=>$b['sha256']];
    }

    /**
     * Explicit, guarded one-document restore. No automatic retries or force
     * overwrites: refuse if the destination revision has changed.
     */
    public function restore(string $id,string $tenant,string $collection,
                            int $expectedCurrentRevision):array {
        self::validate($tenant,$collection);
        if($expectedCurrentRevision<0)throw new InvalidArgumentException('Invalid CAS revision');
        $row=$this->read($id);
        $p=$row['payload'];
        if($p['tenant']!==$tenant||$p['collection']!==$collection) {
            throw new RuntimeException('Backup does not belong to target scope');
        }
        $items=$p['items'];
        $this->store->mutate($tenant,$collection,
            static function(array &$target)use($items):void{$target=$items;},
            $expectedCurrentRevision);
        return ['restored'=>true,'from_revision'=>$p['revision'],
            'new_revision'=>$expectedCurrentRevision+1];
    }
}
