<?php
date_default_timezone_set('Asia/Jakarta');

function cfg($k,$d=null) {
    static $c=null;
    if($c===null) {
        $f=__DIR__.'/config.php';
        $c=is_file($f)?require $f:[];
        if(!is_array($c))$c=[];
    }
    $u=strtoupper($k);
    $e=getenv($u);
    if($e===false||$e==='')$e=$_ENV[$u]??$_SERVER[$u]??false;
    return ($e!==false&&$e!=='')?$e:($c[$k]??$d);
}

function admin_set() {
    return cfg('admin_pass_hash','')!==''||cfg('admin_pass','')!=='';
}

function admin_ok($pw) {
    $h=cfg('admin_pass_hash','');
    if($h!=='')return password_verify($pw,$h);
    $p=cfg('admin_pass','');
    return $p!==''&&hash_equals($p,$pw);
}

function db_dsn($name) {
    return 'pgsql:host='.cfg('db_host','127.0.0.1').';port='.(int)cfg('db_port',5432).';dbname='.$name.';sslmode='.cfg('db_ssl','require');
}

function db_connect() {
    $name=preg_replace('/[^A-Za-z0-9_]/','',cfg('db_name','kereenak'));
    $user=cfg('db_user','avnadmin');
    $pass=cfg('db_pass','');
    $opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_BOTH];
    try {
        $pdo=new PDO(db_dsn($name),$user,$pass,$opt);
    } catch(PDOException $e) {
        if(stripos($e->getMessage(),'does not exist')===false)throw $e;
        $pdo=null;
        foreach(['defaultdb','postgres'] as $adm) {
            try {
                $p=new PDO(db_dsn($adm),$user,$pass,$opt);
                $p->exec('CREATE DATABASE "'.$name.'"');
                $pdo=new PDO(db_dsn($name),$user,$pass,$opt);
                break;
            } catch(PDOException $x) {
            }
        }
        if(!$pdo)throw $e;
    }
    $pdo->exec("SET TIME ZONE 'Asia/Jakarta'");
    return $pdo;
}

function db_install($db) {
    $n=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name IN('menu','orders','items','kas','sessions')")->fetchColumn();
    if($n>=5)return;
    $t=[
        "CREATE TABLE IF NOT EXISTS menu(id SERIAL PRIMARY KEY,name VARCHAR(150) NOT NULL,descr TEXT,price INT NOT NULL DEFAULT 0,badge VARCHAR(60) DEFAULT '',img TEXT,stok INT NOT NULL DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS orders(id SERIAL PRIMARY KEY,name VARCHAR(150),phone VARCHAR(40),campus VARCHAR(200),total INT NOT NULL DEFAULT 0,\"at\" TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP,status VARCHAR(20) DEFAULT 'Baru',jenis VARCHAR(20),tgl VARCHAR(20),alamat TEXT,catatan TEXT,bayar VARCHAR(20),bukti TEXT,kode VARCHAR(20),ulasan TEXT,ongkir INT DEFAULT 0,diskon INT DEFAULT 0,step INT DEFAULT 0,rating INT DEFAULT 0,lat DOUBLE PRECISION,lng DOUBLE PRECISION)",
        "CREATE INDEX IF NOT EXISTS orders_kode_idx ON orders(kode)",
        "CREATE TABLE IF NOT EXISTS items(oid INT,name VARCHAR(150),price INT,qty INT)",
        "CREATE INDEX IF NOT EXISTS items_oid_idx ON items(oid)",
        "CREATE TABLE IF NOT EXISTS kas(id SERIAL PRIMARY KEY,tipe VARCHAR(10),ket VARCHAR(255),jumlah INT,\"at\" TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS sessions(id VARCHAR(128) PRIMARY KEY,data TEXT,ts INT)",
    ];
    foreach($t as $q)$db->exec($q);
    if(!(int)$db->query("SELECT COUNT(*) FROM menu")->fetchColumn()) {
        $s=$db->prepare("INSERT INTO menu(name,descr,price,badge,img,stok) VALUES(?,?,?,?,'',50)");
        foreach([
            ['Ayam Geprek','Nasi, ayam crispy, sambal bawang, lalapan',10000,'Paling Laris'],
            ['Ricebowl Katsu Sambal Matah','Nasi, ayam, sambal matah',13000,'Favorit'],
            ['Nasi Ayam Suwir','Nasi, ayam suwir balado, lalapan',13000,'Pedas Nikmat'],
            ['Nasi Goreng','Nasi, telur, lalapan',15000,'Klasik'],
            ['Sandwich','Roti tawar dengan isi sosis, selada, telur, mentimun, saus dan mayones',12000,'Praktis'],
            ['Ayam Bumbu Teriyaki','Nasi, ayam bumbu teriyaki',15000,'Baru'],
        ] as $r)$s->execute($r);
    }
}

class DbSession implements SessionHandlerInterface {
    private $db;
    public function __construct($db) { $this->db=$db; }
    public function open(string $p,string $n):bool { return true; }
    public function close():bool { return true; }
    public function read(string $id):string|false {
        $s=$this->db->prepare("SELECT data FROM sessions WHERE id=?");
        $s->execute([$id]);
        $r=$s->fetchColumn();
        return $r===false?'':(string)$r;
    }
    public function write(string $id,string $data):bool {
        return $this->db->prepare("INSERT INTO sessions(id,data,ts) VALUES(?,?,?) ON CONFLICT (id) DO UPDATE SET data=EXCLUDED.data,ts=EXCLUDED.ts")->execute([$id,$data,time()]);
    }
    public function destroy(string $id):bool {
        $this->db->prepare("DELETE FROM sessions WHERE id=?")->execute([$id]);
        return true;
    }
    public function gc(int $max):int|false {
        $s=$this->db->prepare("DELETE FROM sessions WHERE ts<?");
        $s->execute([time()-$max]);
        return $s->rowCount();
    }
}

function img_data($tmp,$max=800,$q=78) {
    $info=@getimagesize($tmp);
    if(!$info)return '';
    $raw=file_get_contents($tmp);
    if(function_exists('imagecreatefromstring')) {
        $im=@imagecreatefromstring($raw);
        if($im) {
            $w=imagesx($im);
            $h=imagesy($im);
            $r=min(1,$max/max($w,$h));
            $nw=max(1,(int)round($w*$r));
            $nh=max(1,(int)round($h*$r));
            $c=imagecreatetruecolor($nw,$nh);
            imagefill($c,0,0,imagecolorallocate($c,255,255,255));
            imagecopyresampled($c,$im,0,0,0,0,$nw,$nh,$w,$h);
            ob_start();
            imagejpeg($c,null,$q);
            return 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());
        }
    }
    if(strlen($raw)>700000)return '';
    return 'data:'.$info['mime'].';base64,'.base64_encode($raw);
}

function qris_file() {
    foreach([__DIR__.'/qris.png',__DIR__.'/public/qris.png'] as $f)if(is_file($f))return $f;
    return null;
}

try {
    $db=db_connect();
    db_install($db);
} catch(Throwable $e) {
    http_response_code(500);
    exit('Database tidak dapat dihubungi. Periksa config.php');
}
session_set_save_handler(new DbSession($db),true);
session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/',
    'httponly'=>true,
    'samesite'=>'Lax',
    'secure'=>!empty($_SERVER['HTTPS'])||(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'),
]);
session_start();
