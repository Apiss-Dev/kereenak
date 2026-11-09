<?php
require __DIR__.'/db.php';
const PROV_NAME='Perumahan Bojong Menteng';
const PROV_ST='Jl. Borneo Utara V No. 221';
const PROV_ADDR=PROV_NAME.', '.PROV_ST;
const ORIGIN=PROV_ADDR.', Bekasi, Jawa Barat';
const COURIER_NAME='Raihan Erza';
const COURIER_PLATE='Honda PCX B 80 IDN';
define('GMAPS_KEY',cfg('gmaps_key',''));
const BANK='Seabank 901707814600 a.n. Joyce Margaretha';
const ONGKIR=2500;
const PLAN=['sekali'=>['Sekali Antar','Satu kali pengantaran di tanggal pilihanmu',1,0,'Ongkir Rp2.500']];
const PAY=['qris'=>['QRIS','Scan dan bayar dengan e-wallet atau m-banking'],'cod'=>['COD (Bayar di Tempat)','Bayar tunai ke kurir saat makanan tiba'],'transfer'=>['Transfer Bank','Transfer manual lalu unggah buktinya']];
const ICON=['<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>','<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>','<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>','<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'];
function idtgl($d) {
    $t=strtotime($d);
    if(!$t)return '-';
    $b=['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return date('j',$t).' '.$b[date('n',$t)-1].' '.date('Y',$t);
}
function h($s) {
    return htmlspecialchars($s??'');
}
function rp($n) {
    return 'Rp'.number_format($n,0,',','.');
}
function sumHtml($db,$cart) {
    $sub=0;
    $o='';
    foreach($cart as $id=>$q) {
        $m=$db->query("SELECT * FROM menu WHERE id=".(int)$id)->fetch(PDO::FETCH_ASSOC);
        if(!$m)continue;
        $sub+=$m['price']*$q;
        $max=$q>=(int)$m['stok'];
        $o.='<div class="si"><div class="th2">'.($m['img']?'<img src="'.h($m['img']).'" alt="" style="width:100%;height:100%;border-radius:10px;object-fit:cover">':'🍛').'</div>'
          .'<div class="nm"><b>'.h($m['name']).'</b><small>'.rp($m['price']).' / porsi</small>'
          .'<div class="sstp"><button type="button" data-id="'.(int)$m['id'].'" data-d="-1" title="'.($q<=1?'Hapus dari pesanan':'Kurangi').'" aria-label="'.($q<=1?'Hapus':'Kurangi').'">'.($q<=1?'✕':'−').'</button>'
          .'<span>'.$q.'</span>'
          .'<button type="button" data-id="'.(int)$m['id'].'" data-d="1" aria-label="Tambah" '.($max?'disabled title="Stok maksimal tercapai"':'').'>+</button></div>'
          .($max?'<small style="color:#f5c27a">Stok maksimal tercapai</small>':'').'</div>'
          .'<b>'.rp($m['price']*$q).'</b></div>';
    }
    $o.='<div class="sr"><span>Jumlah hari antar</span><b>1 hari</b></div>'
      .'<div class="sr"><span>Subtotal</span><b>'.rp($sub).'</b></div>'
      .'<div class="sr"><span>Diskon langganan</span><b style="color:var(--l)">-Rp0</b></div>'
      .'<div class="sr"><span>Ongkir</span><b>'.rp(ONGKIR).'</b></div>'
      .'<div class="tt"><span style="font-size:12px;color:#c9d8ce">Total pembayaran</span><b>'.rp($sub+ONGKIR).'</b></div>';
    return $o;
}
$cart=&$_SESSION['cart'];
$cart=$cart??[];
$a=$_GET['a']??'';
$p=$_GET['p']??'home';
if($p=='cart')$p='checkout';
if($a=='upd') {
    $id=(int)$_GET['id'];
    $st=(int)$db->query("SELECT stok FROM menu WHERE id=$id")->fetchColumn();
    $cart[$id]=max(0,min($st,($cart[$id]??0)+(int)$_GET['d']));
    if($cart[$id]<=0)unset($cart[$id]);
    if(isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        $res=['q'=>$cart[$id]??0,'n'=>array_sum($cart)];
        if(isset($_GET['sum']))$res['html']=sumHtml($db,$cart);
        exit(json_encode($res));
    }
    header('Location: index.php?p=checkout');
    exit;
}
if($a=='order'&&$_SERVER['REQUEST_METHOD']=='POST'&&$cart) {
    $by=isset(PAY[$_POST['bayar']??''])?$_POST['bayar']:'cod';
    $alamat=trim($_POST['alamat']);
    $lat=is_numeric($_POST['lat']??'')?(float)$_POST['lat']:null;
    $lng=is_numeric($_POST['lng']??'')?(float)$_POST['lng']:null;
    if($alamat===''&&$lat===null) {
        header('Location: index.php?p=checkout&e='.urlencode('Ketik alamat tujuan atau pilih lokasi di peta'));
        exit;
    }
    if($alamat==='')$alamat='Titik peta '.$lat.','.$lng;
    $sub=0;
    $rows=[];
    foreach($cart as $id=>$q) {
        $m=$db->query("SELECT * FROM menu WHERE id=".(int)$id)->fetch(PDO::FETCH_ASSOC);
        if(!$m)continue;
        if($m['stok']<$q) {
            header('Location: index.php?p=checkout&e='.urlencode($m['name'].': stok tinggal '.$m['stok']));
            exit;
        }
        $rows[]=[$m,$q];
        $sub+=$m['price']*$q;
    }
    $bukti='';
    if($by!='cod') {
        $f=$_FILES['bukti']??null;
        if(!$f||$f['error']!=0||$f['size']>5000000||!@getimagesize($f['tmp_name'])) {
            header('Location: index.php?p=checkout&e='.urlencode('Unggah bukti pembayaran berupa gambar (PNG/JPG, maks 5 MB)'));
            exit;
        }
        $bukti=img_data($f['tmp_name'],1200,75);
        if($bukti==='') {
            header('Location: index.php?p=checkout&e='.urlencode('Gambar bukti tidak dapat diproses, coba file PNG/JPG lain'));
            exit;
        }
    }
    $kode='KRN-'.mt_rand(100000,999999);
    $db->prepare("INSERT INTO orders(name,phone,campus,total,jenis,tgl,alamat,catatan,bayar,bukti,ongkir,kode,lat,lng) VALUES(?,?,?,?,'sekali',?,?,?,?,?,?,?,?,?)")->execute([trim($_POST['name']),trim($_POST['phone']),trim($_POST['campus']),$sub+ONGKIR,$_POST['tgl'],$alamat,trim($_POST['catatan']),$by,$bukti,ONGKIR,$kode,$lat,$lng]);
    $oid=$db->lastInsertId('orders_id_seq');
    foreach($rows as [$m,$q]) {
        $db->prepare("INSERT INTO items VALUES(?,?,?,?)")->execute([$oid,$m['name'],$m['price'],$q]);
        $db->exec("UPDATE menu SET stok=stok-$q WHERE id=".(int)$m['id']);
    }
    $cart=[];
    header('Location: index.php?p=status&k='.$kode);
    exit;
}
if($a=='rate'&&$_SERVER['REQUEST_METHOD']=='POST') {
    $r=max(1,min(5,(int)$_POST['rating']));
    $db->prepare("UPDATE orders SET rating=?,ulasan=? WHERE kode=? AND step>=3 AND status<>'Batal' AND rating=0")->execute([$r,trim($_POST['ulasan']),$_POST['kode']]);
    header('Location: index.php?p=status&k='.urlencode($_POST['kode']));
    exit;
}
$adm=!empty($_SESSION['adm']);
if($p=='struk'&&!$adm) {
    header('Location: index.php?p=admin');
    exit;
}
if($a=='login'&&admin_ok($_POST['pw']??'')) {
    session_regenerate_id(true);
    $_SESSION['adm']=1;
    header('Location: index.php?p=admin');
    exit;
}
if($adm&&$a=='bukti') {
    $d=$db->query("SELECT bukti FROM orders WHERE id=".(int)($_GET['id']??0))->fetchColumn();
    if($d&&preg_match('#^data:(image/[a-z+.-]+);base64,(.+)$#s',$d,$mm)) {
        header('Content-Type: '.$mm[1]);
        echo base64_decode($mm[2]);
    } else {
        http_response_code(404);
    }
    exit;
}
if($a=='qris') {
    $qf=qris_file();
    if($qf) {
        header('Content-Type: image/png');
        readfile($qf);
    } else {
        http_response_code(404);
    }
    exit;
}
if($a=='logout') {
    unset($_SESSION['adm']);
    header('Location: index.php');
    exit;
}
if($adm&&$a=='save'&&$_SERVER['REQUEST_METHOD']=='POST') {
    $img='';
    if(!empty($_FILES['img']['tmp_name'])) {
        $img=img_data($_FILES['img']['tmp_name'],640,78);
    }
    $db->prepare("INSERT INTO menu(name,descr,price,badge,img,stok) VALUES(?,?,?,?,?,?)")->execute([$_POST['name'],$_POST['descr'],(int)$_POST['price'],$_POST['badge'],$img,(int)$_POST['stok']]);
    header('Location: index.php?p=admin');
    exit;
}
if($adm&&$a=='upd_menu') {
    $id=(int)$_POST['id'];
    $db->prepare("UPDATE menu SET price=?,stok=? WHERE id=?")->execute([(int)$_POST['price'],(int)$_POST['stok'],$id]);
    if(!empty($_FILES['img']['tmp_name'])) {
        $img=img_data($_FILES['img']['tmp_name'],640,78);
        if($img!=='')$db->prepare("UPDATE menu SET img=? WHERE id=?")->execute([$img,$id]);
    }
    header('Location: index.php?p=admin');
    exit;
}
if($adm&&$a=='step') {
    $id=(int)$_GET['id'];
    $d=((int)($_GET['d']??1))>0?1:-1;
    $db->prepare("UPDATE orders SET step=GREATEST(0,LEAST(step+?,3)) WHERE id=? AND status<>'Batal'")->execute([$d,$id]);
    header('Location: index.php?p=admin&t=pes');
    exit;
}
if($adm&&$a=='status') {
    $id=(int)$_GET['id'];
    $o=$db->query("SELECT status FROM orders WHERE id=$id")->fetchColumn();
    $st=in_array($_GET['s'],['Baru','Selesai','Batal'])?$_GET['s']:'Baru';
    if($o!='Batal') {
        if($st=='Batal')foreach($db->query("SELECT * FROM items WHERE oid=$id") as $i)$db->prepare("UPDATE menu SET stok=stok+? WHERE name=?")->execute([$i['qty'],$i['name']]);
        $db->exec("UPDATE orders SET status='$st' WHERE id=$id");
    }
    header('Location: index.php?p=admin');
    exit;
}
if($adm&&$a=='kas'&&$_SERVER['REQUEST_METHOD']=='POST') {
    $db->prepare("INSERT INTO kas(tipe,ket,jumlah) VALUES(?,?,?)")->execute([$_POST['tipe']=='masuk'?'masuk':'keluar',$_POST['ket'],(int)$_POST['jumlah']]);
    header('Location: index.php?p=admin');
    exit;
}
if($adm&&$a=='kasdel') {
    $db->exec("DELETE FROM kas WHERE id=".(int)$_GET['id']);
    header('Location: index.php?p=admin');
    exit;
}
if($adm&&$a=='del') {
    $db->exec("DELETE FROM menu WHERE id=".(int)$_GET['id']);
    header('Location: index.php?p=admin');
    exit;
}
$count=array_sum($cart);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kereenak</title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,800&family=DM+Sans:wght@400;500;700&family=Permanent+Marker&family=Caveat+Brush&display=swap" rel="stylesheet">
<style>
    :root {
        --g:#173F2D;
        --o:#EF5D38;
        --l:#E4FF4F;
        --c:#FBF8EC;
        --d:#132F23
    }

    * {
        box-sizing:border-box
    }

    body {
        margin:0;
        font-family:'DM Sans',sans-serif;
        background:var(--c);
        color:var(--g)
    }

    a {
        color:inherit;
        text-decoration:none
    }

    .hd {
        font-family:'Bricolage Grotesque',sans-serif;
        letter-spacing:-.04em
    }

    header {
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:22px 96px;
        height:86px
    }

    .logo {
        font:800 27px 'Bricolage Grotesque';
        letter-spacing:-.06em
    }

    .logo b {
        color:var(--o)
    }

    .logo i {
        color:#9bd13a;
        font-style:normal
    }

    nav {
        display:flex;
        gap:38px;
        font-size:15px;
        font-weight:500;
        position:absolute;
        left:50%;
        transform:translateX(-50%)
    }

    .cartb {
        display:flex;
        align-items:center;
        gap:10px;
        border:1px solid #ece7d6;
        border-radius:40px;
        padding:14px 22px;
        font-weight:700;
        font-size:15px;
        background:#fffdf6
    }

    .cartb span {
        background:var(--o);
        color:#fff;
        border-radius:50%;
        width:22px;
        height:22px;
        display:grid;
        place-items:center;
        font-size:11px
    }

    .hero {
        display:grid;
        grid-template-columns:1fr 1fr;
        height:650px
    }

    .hl {
        background:var(--g);
        padding:0 127px;
        display:flex;
        flex-direction:column;
        justify-content:center;
        color:#fff
    }

    .eye {
        font-size:11px;
        font-weight:700;
        letter-spacing:.18em;
        color:var(--l);
        display:flex;
        align-items:center;
        gap:12px;
        text-transform:uppercase
    }

    .eye:before {
        content:"";
        width:24px;
        height:2px;
        background:var(--o)
    }

    .hl h1 {
        font:800 84px/.95 'Bricolage Grotesque';
        letter-spacing:-.05em;
        margin:34px 0 36px
    }

    .hl h1 em {
        color:var(--l);
        font-style:normal
    }

    .hl p {
        font-size:17px;
        line-height:1.65;
        color:#dfe8e0;
        max-width:520px;
        margin:0 0 40px
    }

    .cta {
        display:flex;
        align-items:center;
        gap:32px
    }

    .btn {
        background:var(--l);
        color:var(--g);
        font-weight:700;
        padding:19px 24px;
        border-radius:40px;
        font-size:16px
    }

    .av {
        display:flex
    }

    .av span {
        width:30px;
        height:30px;
        border-radius:50%;
        display:grid;
        place-items:center;
        font-size:9px;
        font-weight:700;
        margin-right:-6px;
        border:2px solid var(--g)
    }

    .hr {
        position:relative;
        background:#FDE6CF;
        overflow:hidden
    }

    .hr svg {
        position:absolute;
        inset:0;
        width:100%;
        height:100%
    }

    .tag {
        position:absolute;
        left:32px;
        bottom:40px;
        background:#fff;
        color:var(--g);
        border-radius:14px;
        padding:16px 20px;
        font-size:13px;
        font-weight:700;
        box-shadow:0 8px 24px #0002
    }

    .tag small {
        display:block;
        font-weight:400;
        font-size:10px;
        color:#777;
        margin-top:4px
    }

    .mq {
        background:var(--l);
        height:62px;
        display:flex;
        justify-content:space-around;
        align-items:center;
        font-weight:700;
        font-size:12px;
        letter-spacing:.14em;
        text-transform:uppercase
    }

    .mq i {
        color:var(--o);
        font-style:normal
    }

    .menu {
        padding:110px 110px 120px
    }

    .mh {
        display:flex;
        justify-content:space-between;
        align-items:flex-end;
        margin-bottom:38px
    }

    .menu h2 {
        font:500 56px/1 'Bricolage Grotesque';
        letter-spacing:-.05em;
        margin:16px 0 0
    }

    .eye.o {
        color:var(--o)
    }

    .ctl {
        display:flex;
        gap:14px;
        align-items:center
    }

    .pills {
        background:#f1eedf;
        border-radius:40px;
        padding:6px;
        display:flex
    }

    .pills button {
        border:0;
        background:none;
        font:500 13px 'DM Sans';
        padding:11px 18px;
        border-radius:30px;
        color:var(--g);
        cursor:pointer
    }

    .pills .on {
        background:var(--g);
        color:#fff
    }

    .pg {
        width:42px;
        height:42px;
        border-radius:50%;
        border:1px solid #e7e3d2;
        background:#fff;
        cursor:pointer;
        color:var(--g)
    }

    .grid {
        display:grid;
        grid-template-columns:repeat(3,1fr);
        gap:25px
    }

    .card {
        background:#fff;
        border-radius:24px;
        border:1px solid #eee9d9;
        overflow:hidden;
        position:relative;
        transition:.25s
    }

    .card:hover {
        transform:translateY(-6px);
        box-shadow:0 18px 40px #173f2d1a
    }

    .grid .card .im {
        height:265px;
        background:linear-gradient(135deg,#f6d5a8,#ef9a62);
        display:grid;
        place-items:center;
        font-size:70px
    }

    .grid .card img {
        width:100%;
        height:265px;
        object-fit:cover;
        display:block
    }

    .bd {
        position:absolute;
        top:16px;
        left:16px;
        background:var(--l);
        font-size:9px;
        font-weight:700;
        letter-spacing:.1em;
        padding:8px 12px;
        border-radius:20px;
        text-transform:uppercase
    }

    .cb {
        padding:28px 22px 22px
    }

    .cb h3 {
        margin:0 0 12px;
        font:500 19px 'DM Sans'
    }

    .cb p {
        margin:0 0 30px;
        font-size:12px;
        color:#6f8077
    }

    .pr {
        display:flex;
        justify-content:space-between;
        align-items:center
    }

    .pr b {
        font:700 22px 'DM Sans';
        letter-spacing:-.02em
    }

    .add {
        background:var(--g);
        color:#fff;
        border-radius:30px;
        padding:13px 20px;
        font-weight:700;
        font-size:13px
    }

    .how {
        background:var(--o);
        padding:90px 110px;
        display:grid;
        grid-template-columns:1fr 2fr;
        gap:60px;
        align-items:center;
        color:#fff
    }

    .how .eye {
        color:#fff
    }

    .how .eye:before {
        background:var(--l)
    }

    .how h2 {
        font:800 58px/.98 'Bricolage Grotesque';
        letter-spacing:-.05em;
        margin:18px 0 0
    }

    .st {
        display:grid;
        grid-template-columns:repeat(3,1fr);
        gap:15px
    }

    .s {
        background:var(--c);
        color:var(--g);
        border-radius:20px;
        padding:34px 24px;
        height:210px;
        position:relative
    }

    .s em {
        position:absolute;
        right:18px;
        top:6px;
        font:800 36px 'Bricolage Grotesque';
        color:#e8dfc8;
        font-style:normal
    }

    .s h4 {
        margin:30px 0 8px;
        font:500 17px 'DM Sans'
    }

    .s p {
        margin:0;
        font-size:12px;
        line-height:1.6;
        color:#7a8a80
    }

    footer {
        background:var(--d);
        padding:68px 110px 28px;
        color:#b6c8bc
    }

    .ft {
        display:flex;
        justify-content:space-between;
        padding-bottom:42px;
        border-bottom:1px solid #ffffff1f
    }

    .ft p {
        margin:0;
        font-size:17px;
        line-height:1.6;
        max-width:380px
    }

    .ft .logo {
        color:#fff
    }

    .cp {
        font-size:12px;
        padding-top:26px;
        color:#7d9486
    }

    input,select {
        width:100%;
        padding:14px;
        border:1px solid #e1dcc9;
        border-radius:12px;
        margin:6px 0 14px;
        font:15px 'DM Sans'
    }

    table {
        width:100%;
        border-collapse:collapse
    }

    td,th {
        padding:10px 6px;
        border-bottom:1px solid #f0ecdc;
        text-align:left;
        font-size:14px
    }

    button.btn {
        border:0;
        cursor:pointer;
        font-family:'DM Sans'
    }

    @media(max-width:900px) {
        header {
            padding:18px 20px
        }
        nav {
            display:none
        }
        .hero,.how {
            grid-template-columns:1fr
        }
        .hl {
            padding:60px 24px
        }
        .hl h1 {
            font-size:54px
        }
        .hr {
            height:420px
        }
        .menu,.how,footer {
            padding:60px 20px
        }
        .grid,.st {
            grid-template-columns:1fr
        }
        .mh {
            flex-direction:column;
            align-items:flex-start;
            gap:20px
        }
        .mq {
            overflow:hidden;
            font-size:10px
        }
        .ft {
            flex-direction:column;
            gap:20px
        }
    }

    [hidden] {
        display:none!important
    }

    body.alt {
        background:#F3F0E4
    }

    button.add {
        border:0;
        cursor:pointer;
        font:700 13px 'DM Sans'
    }

    .stp {
        display:flex;
        align-items:center;
        gap:12px;
        background:var(--g);
        color:#fff;
        border-radius:30px;
        padding:5px;
        font-weight:700
    }

    .stp button {
        width:34px;
        height:34px;
        border-radius:50%;
        border:0;
        background:var(--l);
        color:var(--g);
        font:700 18px 'DM Sans';
        cursor:pointer;
        line-height:1
    }

    .stp span {
        min-width:14px;
        text-align:center
    }

    .wrap {
        padding:46px 96px 100px
    }

    .wrap h1 {
        font:800 56px/1 'Bricolage Grotesque';
        letter-spacing:-.05em;
        margin:14px 0 34px
    }

    .wrap .sub {
        margin:-18px 0 30px;
        font-size:15px;
        color:#5d7266
    }

    .card {
        background:#fff;
        border-radius:26px;
        padding:28px 32px 32px;
        margin-bottom:22px
    }

    .card h3 {
        font:500 24px 'DM Sans';
        letter-spacing:-.03em;
        margin:0
    }

    .ckg,.spg {
        display:grid;
        gap:24px;
        align-items:start
    }

    .ckg {
        grid-template-columns:1fr 390px
    }

    .spg {
        grid-template-columns:1.25fr 1fr
    }

    .bh {
        display:flex;
        align-items:center;
        gap:20px;
        margin-bottom:20px
    }

    .bh i {
        width:40px;
        height:40px;
        border-radius:50%;
        background:var(--l);
        display:grid;
        place-items:center;
        font:700 12px 'DM Sans';
        font-style:normal
    }

    .bc {
        padding-left:60px
    }

    .fl {
        display:block;
        font-size:12px;
        font-weight:700;
        margin-top:14px
    }

    .fl .opsi {
        float:right;
        font-weight:400;
        color:#9aa59d;
        font-style:normal
    }

    .hint {
        display:block;
        font-size:11px;
        color:#7a8a80;
        margin:6px 0
    }

    .card input,.card textarea,.card select {
        background:#FAF9F1
    }

    textarea {
        width:100%;
        min-height:96px;
        padding:14px;
        border:1px solid #e1dcc9;
        border-radius:12px;
        margin:6px 0 14px;
        font:15px 'DM Sans';
        resize:vertical
    }

    .opt {
        display:flex;
        align-items:center;
        gap:14px;
        border:1px solid #e1dcc9;
        border-radius:14px;
        padding:18px 20px;
        margin-bottom:10px;
        cursor:pointer;
        background:#fff
    }

    .opt input {
        display:none
    }

    .opt .dot {
        width:22px;
        height:22px;
        border-radius:50%;
        border:2px solid #cfcab6;
        flex:none;
        display:grid;
        place-items:center
    }

    .opt:has(input:checked) {
        border-color:var(--g);
        background:#F6F9E8
    }

    .opt:has(input:checked) .dot {
        border-color:var(--g)
    }

    .opt:has(input:checked) .dot:after {
        content:"";
        width:10px;
        height:10px;
        border-radius:50%;
        background:var(--g)
    }

    .opt .ot {
        flex:1
    }

    .opt b {
        display:block;
        font-size:15px
    }

    .opt small {
        font-size:12px;
        color:#7a8a80
    }

    .opt em {
        font-style:normal;
        font-weight:700;
        font-size:13px;
        color:var(--o)
    }

    .pa {
        display:flex;
        gap:12px;
        background:#F0F2E4;
        border:1px solid #e1e5cf;
        border-radius:14px;
        padding:16px 18px
    }

    .pa small {
        display:block;
        font-size:9px;
        letter-spacing:.1em;
        color:#7a8a80;
        margin:2px 0
    }

    .pa b {
        font-size:14px
    }

    .pp {
        background:#F6F9E8;
        border-radius:12px;
        padding:16px;
        font-size:13px;
        margin-top:8px
    }

    .qr {
        width:180px;
        height:180px;
        object-fit:contain;
        display:block;
        margin-bottom:8px
    }

    .qrp {
        width:180px;
        height:180px;
        border:2px dashed #cfcab6;
        border-radius:12px;
        display:grid;
        place-items:center;
        text-align:center;
        font-size:11px;
        color:#8a9a90;
        padding:10px;
        margin-bottom:8px
    }

    .drop {
        border:2px dashed #d7d2bd;
        border-radius:16px;
        padding:26px;
        text-align:center;
        cursor:pointer;
        display:block;
        background:#FAF9F1
    }

    .drop input {
        display:none
    }

    .drop img {
        max-width:220px;
        max-height:220px;
        border-radius:12px;
        margin-top:10px
    }

    #mp,#map,.mapf {
        width:100%;
        height:340px;
        border:0;
        border-radius:18px;
        background:#e8e4d3;
        margin-top:6px
    }

    #map {
        height:380px
    }

    .mapf.ph {
        display:grid;
        place-items:center;
        color:#7a8a80;
        font-size:13px;
        text-align:center;
        padding:20px
    }

    .sum {
        background:var(--g);
        color:#fff;
        border-radius:26px;
        padding:28px 32px;
        position:sticky;
        top:24px;
        box-shadow:0 24px 50px #173f2d33
    }

    .sh {
        display:flex;
        justify-content:space-between;
        font-weight:700;
        padding-bottom:20px;
        border-bottom:1px solid #ffffff22
    }

    .sh a {
        color:var(--l);
        font-size:12px
    }

    .si {
        display:flex;
        gap:12px;
        align-items:center;
        padding:18px 0;
        border-bottom:1px solid #ffffff22;
        font-size:13px
    }

    .th2 {
        width:48px;
        height:48px;
        border-radius:10px;
        object-fit:cover;
        background:#ef9a62;
        flex:none;
        display:grid;
        place-items:center
    }

    .nm {
        flex:1
    }

    .si small {
        display:block;
        font-size:10px;
        color:#b6c8bc;
        margin-top:4px
    }

    .sr {
        display:flex;
        justify-content:space-between;
        font-size:12px;
        color:#c9d8ce;
        margin:10px 0
    }

    .sr b {
        color:#fff
    }

    .tt {
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin:22px 0
    }

    .tt b {
        font:800 34px 'Bricolage Grotesque';
        color:var(--l)
    }

    .sstp {
        display:inline-flex;
        align-items:center;
        gap:10px;
        background:#ffffff1f;
        border-radius:30px;
        padding:3px;
        margin-top:8px;
        font-weight:700
    }

    .sstp button {
        width:26px;
        height:26px;
        border-radius:50%;
        border:0;
        background:var(--l);
        color:var(--g);
        font:700 14px 'DM Sans';
        cursor:pointer;
        line-height:1
    }

    .sstp button:disabled {
        opacity:.35;
        cursor:not-allowed
    }

    .sstp span {
        min-width:16px;
        text-align:center;
        font-size:13px
    }

    #sumdyn {
        transition:opacity .15s
    }

    .btn.big {
        width:100%;
        text-align:center;
        padding:19px;
        font-size:17px
    }

    .btn.dk {
        background:var(--g);
        color:#fff;
        display:inline-block;
        margin-top:10px
    }

    .btn.sm {
        display:inline-block;
        padding:11px 18px;
        font-size:13px
    }

    .btn.ok2 {
        background:#d9f2c4
    }

    .note {
        font-size:10px;
        color:#9fb5a7;
        text-align:center;
        margin:14px 0 0
    }

    .sph {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:20px
    }

    .sph h1 {
        max-width:720px
    }

    .kode {
        background:var(--l);
        border-radius:16px;
        padding:18px 26px;
        box-shadow:6px 6px 0 var(--g);
        transform:rotate(2deg)
    }

    .kode small {
        display:block;
        font-size:9px;
        font-weight:700;
        letter-spacing:.12em
    }

    .kode b {
        font:800 28px 'Bricolage Grotesque';
        letter-spacing:-.03em;
        display:block;
        margin:6px 0
    }

    .top {
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin-bottom:26px
    }

    .top small,.lbl {
        font-size:10px;
        letter-spacing:.12em;
        color:#7a8a80
    }

    .lbl {
        display:block;
        margin:22px 0 4px
    }

    .tl {
        display:flex;
        gap:16px;
        padding-bottom:26px;
        position:relative
    }

    .tl:not(:last-of-type):before {
        content:"";
        position:absolute;
        left:19px;
        top:42px;
        bottom:2px;
        width:2px;
        background:#e2ddc9
    }

    .tl.done:before {
        background:var(--g)
    }

    .tc {
        width:38px;
        height:38px;
        border-radius:50%;
        border:2px solid #dcd7c3;
        display:grid;
        place-items:center;
        flex:none;
        background:#fff;
        color:#a9a99b
    }

    .now .tc {
        background:var(--l);
        border-color:var(--l);
        color:var(--g)
    }

    .done .tc {
        background:var(--g);
        border-color:var(--g);
        color:var(--l)
    }

    .tl b {
        font:500 16px 'DM Sans'
    }

    .tl small {
        display:block;
        font-size:12px;
        margin-top:4px
    }

    .todo b,.todo small {
        color:#a4a89c
    }

    .pillnow {
        font-size:9px;
        font-weight:700;
        letter-spacing:.1em;
        background:#fde6df;
        color:var(--o);
        padding:7px 12px;
        border-radius:20px;
        margin-left:auto;
        align-self:flex-start;
        font-style:normal
    }

    .dr {
        display:flex;
        justify-content:space-between;
        gap:14px;
        padding:15px 0;
        border-bottom:1px solid #f0ecdc;
        font-size:13px
    }

    .dr span {
        color:#7a8a80;
        font-size:12px
    }

    .dr b {
        text-align:right
    }

    .dr small {
        display:block;
        font-weight:400;
        font-size:10px;
        color:#7a8a80;
        margin-top:3px
    }

    .mi {
        display:flex;
        align-items:center;
        gap:10px;
        margin-top:12px;
        font-size:12px;
        font-weight:700
    }

    .mi .th2 {
        width:38px;
        height:38px
    }

    .mi em {
        margin-left:auto;
        color:var(--o);
        font-style:normal
    }

    .cr {
        display:flex;
        align-items:center;
        gap:14px;
        margin-top:16px;
        font-size:14px
    }

    .cr small {
        color:#7a8a80
    }

    .cprog {
        height:8px;
        background:#ece7d6;
        border-radius:8px;
        margin-top:8px;
        overflow:hidden
    }

    .cprog i {
        display:block;
        height:100%;
        background:var(--g);
        width:0;
        transition:width .6s
    }

    .rt {
        margin-top:8px
    }

    .rt p {
        font-size:13px;
        color:#7a8a80
    }

    .stars {
        font-size:40px;
        line-height:1;
        cursor:pointer;
        color:#d7d2bd;
        letter-spacing:6px;
        margin:10px 0 4px;
        user-select:none
    }

    .stars span.on,.stars.ro {
        color:#F4B400
    }

    .stars.ro {
        cursor:default
    }

    .tabs {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-bottom:22px
    }

    .tabs a {
        padding:11px 20px;
        border-radius:30px;
        background:#e9e5d3;
        font-weight:700;
        font-size:14px
    }

    .tabs .on {
        background:var(--g);
        color:#fff
    }

    .stats {
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:24px;
        margin-bottom:22px
    }

    .stat {
        background:#fff;
        border-radius:26px;
        padding:22px 26px
    }

    .stat small {
        color:#7a8a80;
        font-size:12px
    }

    .stat b {
        display:block;
        font:800 24px 'Bricolage Grotesque';
        letter-spacing:-.03em;
        margin-top:6px
    }

    .fg {
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:0 20px
    }

    .rw {
        display:flex;
        gap:12px;
        align-items:center;
        padding:14px 0;
        border-bottom:1px solid #f0ecdc;
        flex-wrap:wrap;
        font-size:13px
    }

    .rw input {
        margin:0;
        width:110px;
        padding:9px
    }

    .rw input[type=file] {
        width:170px;
        font-size:11px
    }

    .pill {
        font-size:11px;
        font-weight:700;
        padding:5px 12px;
        border-radius:20px;
        background:#f1eedf
    }

    .pill.Selesai {
        background:var(--l)
    }

    .pill.Batal {
        background:#f6c9c0
    }

    .in {
        color:#2c8a4b
    }

    .out {
        color:var(--o)
    }

    .lnk {
        font-size:13px;
        font-weight:700;
        color:var(--g);
        text-decoration:underline
    }

    .lnk.out {
        color:var(--o)
    }

    .oh {
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin-bottom:18px
    }

    .oh small {
        display:block;
        color:#7a8a80;
        font-size:12px;
        margin-top:2px
    }

    .ok {
        font:800 22px 'Bricolage Grotesque';
        letter-spacing:-.03em
    }

    .og {
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:24px;
        margin-bottom:12px
    }

    .og b {
        display:block;
        font-size:14px
    }

    .og span {
        display:block;
        font-size:12px;
        color:#6f8077;
        margin:3px 0
    }

    .og .lbl {
        margin:0 0 6px
    }

    .oa {
        display:flex;
        gap:16px;
        align-items:center;
        margin-top:18px;
        flex-wrap:wrap
    }

    .tools {
        display:flex;
        gap:12px;
        margin-bottom:26px
    }

    .struk {
        background:#fff;
        border-radius:26px;
        padding:32px;
        max-width:420px;
        margin:0 auto;
        font-size:13px
    }

    .struk hr {
        border:0;
        border-top:2px dashed #d7d2bd;
        margin:16px 0
    }

    .sc {
        text-align:center;
        color:#6f8077;
        font-size:12px;
        line-height:1.6;
        margin:10px 0
    }

    .sl {
        display:flex;
        justify-content:space-between;
        gap:14px;
        margin:7px 0
    }

    .sl b {
        text-align:right
    }

    .sl.t {
        font:800 20px 'Bricolage Grotesque'
    }

    @media(max-width:900px) {
        .wrap {
            padding:30px 20px 70px
        }
        .wrap h1 {
            font-size:40px
        }
        .ckg,.spg {
            grid-template-columns:1fr
        }
        .bc {
            padding-left:0
        }
        .sum {
            position:static
        }
        .stats,.fg,.og {
            grid-template-columns:1fr 1fr
        }
        .sph {
            flex-direction:column
        }
        .card,.sum {
            padding:22px
        }
    }

    @media print {
        header,.noprint {
            display:none
        }
        body,body.alt {
            background:#fff
        }
        .wrap {
            padding:0
        }
        .struk {
            max-width:100%;
            padding:0
        }
    }

    .wrap{max-width:1196px;margin:0 auto;padding:40px 48px 90px}
    .wrap h1{font-size:44px;margin:12px 0 24px}
    .wrap .card{border-radius:26px;padding:22px 26px 24px;margin-bottom:18px}
    .wrap .card h3{font-size:19px}
    .bh{gap:14px;margin-bottom:14px}
    .bh i{width:36px;height:36px;font-size:11px}
    .bc{padding-left:50px}
    .fl{font-size:11px;margin-top:10px}
    .ckg{grid-template-columns:1fr 360px;gap:22px}
    .ckg input,.ckg textarea{padding:11px 14px;font-size:13px;margin:5px 0 10px}
    .ckg textarea{min-height:76px}
    .ckg .opt{padding:14px 18px;margin-bottom:8px}
    .ckg .opt b{font-size:14px}
    .ckg .opt small{font-size:11px}
    .ckg .opt em{font-size:12px}
    .ckg .opt .dot{width:20px;height:20px}
    .pa{padding:14px 16px}
    .pa b{font-size:13px}
    #mp,.mapf{height:240px}
    #map{height:300px}
    .qr,.qrp{width:150px;height:150px}
    .drop{padding:18px}
    .sum{border-radius:28px;padding:24px;top:20px}
    .sh{padding-bottom:16px;font-size:14px}
    .si{padding:14px 0}
    .th2{width:46px;height:46px}
    .sr{font-size:11px;margin:9px 0}
    .tt{margin:16px 0;padding-top:16px;border-top:1px solid #ffffff22}
    .tt b{font-size:28px}
    .btn.big{padding:15px;font-size:15px}
    .sph{margin-bottom:26px}
    .sph h1{font-size:64px;line-height:.98;max-width:640px;margin:14px 0 0}
    .sph .kode{padding:14px 20px;border-radius:14px;box-shadow:5px 5px 0 var(--g);margin-top:14px}
    .kode small{font-size:8px}
    .kode b{font-size:22px;margin:4px 0}
    .spg{grid-template-columns:1.6fr 1fr;gap:22px}
    .top{margin-bottom:20px}
    .top small{font-size:9px}
    .tl{gap:14px;padding-bottom:22px}
    .tc{width:36px;height:36px}
    .tc svg{width:15px;height:15px}
    .tl:not(:last-of-type):before{left:17px;top:40px}
    .tl b{font-size:14px}
    .tl small{font-size:11px;margin-top:3px}
    .pillnow{font-size:8px;padding:6px 11px}
    .dr{padding:13px 0;font-size:12px}
    .dr span{font-size:11px}
    .dr small{font-size:9px}
    .mi{font-size:11px}
    .mi .th2{width:38px;height:38px}
    .btn.dk{padding:14px 22px;font-size:12px;margin-top:6px}
    .stars{font-size:30px}
    .rw .th2{width:44px;height:44px;border-radius:10px;object-fit:cover}
    .lgw{min-height:calc(100vh - 190px);display:grid;place-items:center}
    .wrap .lgc{width:100%;max-width:400px;margin:0;padding:32px 30px;text-align:center}
    .lgc .eye{justify-content:center}
    .lgc h2{font:800 30px 'Bricolage Grotesque';letter-spacing:-.04em;margin:10px 0 22px}
    .lgc .fl{text-align:left}
    .lgc input{text-align:left}
    .lgc .err{color:#b3261e;font-size:13px;margin-bottom:10px}
    @media(max-width:900px){
        .wrap{padding:24px 18px 60px}
        .ckg,.spg{grid-template-columns:1fr}
        .bc{padding-left:0}
        .sum{position:static}
        .sph h1{font-size:40px}
    }

    .stpa{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;padding:14px 16px;background:#FAF9F1;border-radius:14px}
    .chips{display:flex;gap:6px;flex-wrap:wrap;flex:1}
    .chip{font-size:11px;font-weight:700;padding:6px 12px;border-radius:20px;background:#ece7d6;color:#8a9a90}
    .chip.on{background:var(--g);color:var(--l)}
</style>
</head>
<body class="<?=$p=='home'?'':'alt'?>">
<header><a href="index.php" class="logo">kere<b>enak</b><i>.</i></a>
  <nav><a href="index.php#menu">Menu</a><a href="index.php#cara">Cara Pesan</a><a href="index.php#tentang">Tentang Kami</a></nav>
  <a href="index.php?p=checkout" class="cartb"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6zM9 8a3 3 0 016 0"/></svg>Keranjang <span id="cc"><?=$count?></span></a></header>
<?php if($p=='home'):?>
  <?php
  $menu=$db->query("SELECT * FROM menu ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  $rt=$db->query("SELECT COUNT(*),AVG(rating) FROM orders WHERE rating>0")->fetch(PDO::FETCH_NUM);
  $rcount=(int)$rt[0];
  $avg=$rcount?number_format($rt[1],1):null;
  $raters=$db->query("SELECT name FROM orders WHERE rating>0 ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
  $avc=['#d9a46e','#EF5D38','#7aa58a'];
  ?>
  <section class="hero">
    <div class="hl">
      <div class="eye">Sahabat mahasiswa di tanggal tua</div>
      <h1>Uang menipis, makan tetap <em>enak.</em></h1>
      <p>Makanan rumahan yang enak, bergizi, dan ramah kantong. Pilihan lengkap 10–15 ribuan, gratis antar ke kampusmu.</p>
      <div class="cta"><a href="#menu" class="btn">Lihat menu hari ini &nbsp;→</a>
        <div style="display:flex;align-items:center;gap:14px">
          <?php if($rcount):?>
            <div class="av"><?php foreach($raters as $i=>$n):?><span style="background:<?=$avc[$i]?>"><?=h(strtoupper(implode('',array_slice(preg_split('//u',trim($n),-1,PREG_SPLIT_NO_EMPTY),0,2))))?></span><?php endforeach;?></div>
            <div>
              <?php $rd=(int)round($rt[1]); ?>
              <div style="font-weight:700;font-size:13px;color:var(--l)"><?=str_repeat('★',$rd).str_repeat('☆',5-$rd)?> <?=$avg?></div>
              <small style="font-size:10px;color:#cfdcd2">dari <?=$rcount?> ulasan pembeli</small>
            </div>
          <?php else:?>
            <div>
              <div style="font-weight:700;font-size:13px;color:var(--l)">☆ Belum ada rating</div>
              <small style="font-size:10px;color:#cfdcd2">Jadilah yang pertama menilai</small>
            </div>
          <?php endif;?>
        </div>
      </div>
    </div>
    <div class="hr">
      <svg viewBox="0 0 787 650" preserveAspectRatio="xMidYMid slice"><rect width="787" height="650" fill="#FDE6CF"/>
<path d="M0 650V330L520 190 787 270V650z" fill="#E8470C"/><path d="M0 0H105Q40 60 0 200z" fill="#EF5D38"/><path d="M0 340 530 195l10 30L0 380z" fill="#C93A0A"/>
<ellipse cx="150" cy="370" rx="55" ry="85" fill="#E2683B" transform="rotate(20 150 370)"/>
<text x="420" y="300" font-family="Permanent Marker" font-size="120" fill="#0F3B2E" transform="rotate(-12 420 300)" text-anchor="middle">KERENAK</text>
<text x="70" y="25" font-family="Caveat Brush" font-size="24" fill="#fff" transform="rotate(-15 40 70) translate(0 90)">Hematnya keterlaluan!</text>
<g transform="rotate(-28 540 420)"><rect x="340" y="300" width="380" height="270" rx="26" fill="#B6272D"/><rect x="354" y="314" width="352" height="242" rx="18" fill="#7a1a20"/>
<rect x="362" y="322" width="336" height="226" rx="12" fill="#D8DAB8" opacity=".25"/>
<ellipse cx="420" cy="380" rx="62" ry="52" fill="#F7F2E8"/><circle cx="420" cy="378" r="22" fill="#F4A21F"/>
<rect x="490" y="326" width="196" height="110" rx="14" fill="#E9893C"/><ellipse cx="410" cy="500" rx="46" ry="34" fill="#4F8A3D"/><ellipse cx="620" cy="500" rx="70" ry="42" fill="#EFC98F"/><circle cx="600" cy="500" r="14" fill="#7a9a3a"/></g>
<rect x="590" y="330" width="150" height="100" rx="14" fill="#9c5a43" transform="rotate(-30 665 380)"/>
<circle cx="640" cy="280" r="56" fill="#E4FF4F"/><circle cx="650" cy="290" r="56" fill="#0F3B2E" opacity=".25"/><circle cx="640" cy="280" r="56" fill="#E4FF4F"/>
<text x="640" y="262" font-size="11" text-anchor="middle" fill="#0F3B2E" font-family="DM Sans">mulai dari</text>
<text x="640" y="296" font-size="44" text-anchor="middle" fill="#0F3B2E" font-family="Bricolage Grotesque" font-weight="800">10K</text>
<text x="640" y="316" font-size="11" text-anchor="middle" fill="#0F3B2E" font-family="DM Sans">/porsi</text>
<text x="200" y="548" font-family="Caveat Brush" font-size="38" fill="#0F3B2E" font-style="italic">Kere boleh, Lapar jangan</text></svg>
      <div class="tag">🕙 Pesan s.d. 10:00<small>Sampai sebelum jam makan siang</small></div>
    </div>
  </section>
  <div class="mq"><span>Porsi bikin kenyang</span><i>✦</i><span>Gratis antar ke kampus</span><i>✦</i><span>Harga anak kos</span><i>✦</i><span>Menu ganti tiap hari</span></div>
  <section class="menu" id="menu">
    <div class="mh">
      <div>
        <div class="eye o">Menu hari ini</div>
        <h2>Pilih penyelamat laparmu</h2>
      </div>
      <div class="ctl">
        <div class="pills"><button class="on" data-f="0">Semua menu</button><button data-f="1">10–12K</button><button data-f="2">13–15K</button></div>
        <button class="pg" id="pv">‹</button><span id="pn" style="font-size:12px">1/1</span><button class="pg" id="nx">›</button></div>
    </div>
    <div class="grid" id="gr">
      <?php foreach($menu as $m):?>
        <div class="card" data-p="<?=$m['price']?>">
          <?php if($m['badge']):?>
            <span class="bd"><?=h($m['badge'])?></span>
          <?php endif;?>
          <?=$m['img']?'<img src="'.h($m['img']).'" alt="">':'<div class="im">🍛</div>'?>
          <div class="cb">
            <h3><?=h($m['name'])?></h3>
            <p><?=h($m['descr'])?></p>
            <div class="pr"><b><?=rp($m['price'])?></b>
              <?php if($m['stok']<=0):?>
                <span class="add" style="background:#c9c6b8">Habis</span>
              <?php else:?>
                <?php $q=$cart[$m['id']]??0; ?>
                <div class="act" data-id="<?=$m['id']?>"><button type="button" class="add" data-d="1" <?=$q?'hidden':''?>>＋ &nbsp;Tambah</button>
                  <div class="stp" <?=$q?'':'hidden'?>><button type="button" data-d="-1">−</button><span><?=$q?></span><button type="button" data-d="1">+</button></div>
                </div>
              <?php endif;?>
            </div>
          </div>
        </div>
      <?php endforeach;?>
    </div>
  </section>
  <section class="how" id="cara">
    <div>
      <div class="eye">Gampang banget</div>
      <h2>Dari klik sampai kenyang</h2>
    </div>
    <div class="st">
      <div class="s"><em>01</em>🛍
        <h4>Pilih menu</h4>
        <p>Ambil menu favoritmu sebelum jam 10 pagi.</p>
      </div>
      <div class="s"><em>02</em>📍
        <h4>Pilih titik kampus</h4>
        <p>Kami antar ke titik kumpul kampusmu.</p>
      </div>
      <div class="s"><em>03</em>🕙
        <h4>Tinggal tunggu</h4>
        <p>Makanan hangat datang sebelum jam makan siang.</p>
      </div>
    </div>
  </section>
  <footer id="tentang">
    <div class="ft">
      <div class="logo" style="font-size:30px">kere<b>enak</b><i>.</i></div>
      <p>Gerakan makan layak untuk setiap mahasiswa, bahkan ketika dompet sedang tidak baik-baik saja.</p>
    </div>
    <div class="cp">© 2025 Kereenak — Dibuat untuk anak kampus Indonesia.</div>
  </footer>
  <script>
      let f=0,pg=0;
      const cs=[...document.querySelectorAll('.card')];
      function r() {
          const v=cs.filter(c=> {
              const p=c.dataset.p/1000;
              return f==0||(f==1&&p>=10&&p<=12)||(f==2&&p>=13&&p<=15)
          });
          const t=Math.max(1,Math.ceil(v.length/3));
          pg=Math.min(pg,t-1);
          cs.forEach(c=>c.style.display='none');
          v.slice(pg*3,pg*3+3).forEach(c=>c.style.display='');
          pn.textContent=(pg+1)+'/'+t;
          pv.style.opacity=pg?1:.4;
          nx.style.opacity=pg<t-1?1:.4;
          window.T=t
      }
      document.querySelectorAll('.pills button').forEach(b=>b.onclick=()=> {
          document.querySelector('.pills .on').classList.remove('on');
          b.classList.add('on');
          f=+b.dataset.f;
          pg=0;
          r()
      });
      pv.onclick=()=> {
          if(pg>0) {
              pg--;
              r()
          }
      };
      nx.onclick=()=> {
          if(pg<T-1) {
              pg++;
              r()
          }
      };
      r();
  </script>
  <script>
      document.querySelectorAll('.act').forEach(function(a) {
          a.addEventListener('click',function(e) {
              var b=e.target.closest('button');
              if(!b)return;
              fetch('?a=upd&ajax=1&id='+a.dataset.id+'&d='+b.dataset.d).then(function(r) {
                  return r.json()
              }).then(function(j) {
                  a.querySelector('.add').hidden=j.q>0;
                  var sp=a.querySelector('.stp');
                  sp.hidden=j.q==0;
                  sp.querySelector('span').textContent=j.q;
                  document.getElementById('cc').textContent=j.n;
              });
          });
      });
  </script>
<?php elseif($p=='checkout'):?>
  <?php
  $ci=[];
  $sub=0;
  foreach($cart as $id=>$q) {
      $m=$db->query("SELECT * FROM menu WHERE id=".(int)$id)->fetch(PDO::FETCH_ASSOC);
      if($m) {
          $ci[]=[$m,$q];
          $sub+=$m['price']*$q;
      }
  }
  ?>
  <div class="wrap">
    <div class="eye o">Checkout</div>
    <h1>Selesaikan pesananmu.</h1>
    <?php if(!empty($_GET['e'])):?>
      <div class="card" style="color:#b3261e">⚠ <?=h($_GET['e'])?></div>
    <?php endif;?>
    <?php if(!$ci):?>
      <div class="card">Keranjangmu masih kosong. <a href="index.php#menu" style="color:var(--o);font-weight:700">Pilih menu →</a></div>
    <?php else:?>
      <form class="ckg" method="post" action="?a=order" enctype="multipart/form-data" onsubmit="return okAlamat()">
        <div>
          <div class="card">
            <div class="bh"><i>01</i>
              <h3>Jenis pengantaran</h3>
            </div>
            <div class="bc">
              <?php foreach(PLAN as $k=>$v):?>
                <label class="opt"><input type="radio" name="jenis" value="<?=$k?>" checked><span class="dot"></span><span class="ot"><b><?=$v[0]?></b><small><?=$v[1]?></small></span><em><?=$v[4]?></em></label>
              <?php endforeach;?>
            </div>
          </div>
          <div class="card">
            <div class="bh"><i>02</i>
              <h3>Jadwal antar</h3>
            </div>
            <div class="bc"><label class="fl">Tanggal pengantaran *</label><input type="date" name="tgl" required min="<?=date('Y-m-d')?>" style="max-width:260px"></div>
          </div>
          <div class="card">
            <div class="bh"><i>03</i>
              <h3>Detail pengiriman</h3>
            </div>
            <div class="bc">
              <div class="pa"><span style="color:var(--o)"><?=ICON[2]?></span>
                <div><small>ALAMAT PENYEDIA MAKANAN</small><b><?=PROV_ADDR?></b><small>Alamat awal tetap dan tidak dapat diubah</small></div>
              </div>
              <label class="fl">Nama penerima *</label><input name="name" required placeholder="Nama lengkap">
              <label class="fl">No. WhatsApp *</label><input name="phone" required placeholder="08xxxxxxxxxx">
              <label class="fl">Alamat tujuan: ketik detail pengiriman atau pilih lewat peta *</label>
              <input id="cari" placeholder="Cari lokasi tujuan di Google Maps" autocomplete="off" hidden>
              <div id="mp"></div>
              <small class="hint" id="kn"></small>
              <textarea name="alamat" id="alamat" placeholder="Contoh: Kos Melati, Jl. Margonda No. 12, Beji"></textarea>
              <input type="hidden" name="lat" id="lat"><input type="hidden" name="lng" id="lng">
              <label class="fl">Kampus atau patokan terdekat *</label><input name="campus" id="campus" required placeholder="Contoh: Gerbang utama Universitas Indonesia">
              <label class="fl">Catatan untuk kurir <em class="opsi">Opsional</em></label><input name="catatan" placeholder="Contoh: Titip di penjaga kos">
            </div>
          </div>
          <div class="card">
            <div class="bh"><i>04</i>
              <h3>Pembayaran</h3>
            </div>
            <div class="bc">
              <?php foreach(PAY as $k=>$v):?>
                <label class="opt"><input type="radio" name="bayar" value="<?=$k?>" <?=$k=='qris'?'checked':''?>><span class="dot"></span><span class="ot"><b><?=$v[0]?></b><small><?=$v[1]?></small></span></label>
              <?php endforeach;?>
              <div class="pp" id="pp-qris">
                <?php if(qris_file()):?>
                  <img class="qr" src="?a=qris" alt="QRIS">
                <?php else:?>
                  <div class="qrp">QRIS Kereenak<br>simpan gambar QRIS sebagai qris.png</div>
                <?php endif;?>
                Scan QRIS lalu unggah bukti pembayaran di kotak 5.</div>
              <div class="pp" id="pp-cod" hidden>Siapkan uang pas saat kurir tiba. Bukti pembayaran tidak wajib.</div>
              <div class="pp" id="pp-transfer" hidden><b><?=BANK?></b><br>Transfer sesuai total, lalu unggah bukti di kotak 5.</div>
            </div>
          </div>
          <div class="card">
            <div class="bh"><i>05</i>
              <h3>Bukti pembayaran</h3>
            </div>
            <div class="bc"><label class="drop"><input type="file" name="bukti" id="bukti" accept="image/*" required><b>Klik untuk unggah bukti (PNG / JPG)</b><br><small>Maks 5 MB · tidak wajib untuk COD</small><br><img id="prev" hidden alt=""></label></div>
          </div>
        </div>
        <aside class="sum">
        <div class="sh"><span>🛍 Ringkasan pesanan</span></div>
        <div id="sumdyn"><?=sumHtml($db,$cart)?></div>
        <button class="btn big">Pesan Sekarang &nbsp;→</button>
        <p class="note">Dengan memesan, kamu menyetujui detail dan biaya di atas.</p>
        </aside>
      </form>
      <script>
          var ORG='<?=h(ORIGIN)?>',KEY='<?=GMAPS_KEY?>',map,pin,dr,ds,gc;
          function g(i) {
              return document.getElementById(i);
          }
          function pay() {
              var v=document.querySelector('[name=bayar]:checked').value;
              ['qris','cod','transfer'].forEach(function(k) {
                  g('pp-'+k).hidden=k!=v;
              });
              g('bukti').required=v!='cod';
          }
          function okAlamat() {
              if(!g('alamat').value.trim()&&!g('lat').value) {
                  alert('Ketik alamat tujuan atau pilih lokasi di peta');
                  return false;
              }
              return true;
          }
          function frame() {
              var d=g('lat').value?g('lat').value+','+g('lng').value:(g('alamat').value+' '+g('campus').value).trim();
              g('mp').innerHTML=d?'<iframe class="mapf" src="https://maps.google.com/maps?saddr='+encodeURIComponent(ORG)+'&daddr='+encodeURIComponent(d)+'&output=embed"></iframe>':'<div class="mapf ph">Ketik alamat tujuan, rute dari dapur akan tampil di sini</div>';
          }
          function choose(ll,addr) {
              g('lat').value=ll.lat();
              g('lng').value=ll.lng();
              var a=g('alamat');
              if(addr&&(!a.value.trim()||a.dataset.auto=='1')) {
                  a.value=addr;
                  a.dataset.auto='1';
              }
              if(!pin)pin=new google.maps.Marker( {
                  map:map,title:'Tujuan'
              });
              pin.setPosition(ll);
              ds.route( {
                  origin:ORG,destination:ll,travelMode:'DRIVING'
              },function(r,st) {
                  if(st=='OK')dr.setDirections(r);
              });
          }
          window.initPick=function() {
              gc=new google.maps.Geocoder();
              ds=new google.maps.DirectionsService();
              map=new google.maps.Map(g('mp'), {
                  center: {
                      lat:-6.29,lng:107.03
                  },zoom:14,streetViewControl:false,mapTypeControl:false
              });
              dr=new google.maps.DirectionsRenderer( {
                  map:map
              });
              gc.geocode( {
                  address:ORG
              },function(r,st) {
                  if(st=='OK') {
                      map.setCenter(r[0].geometry.location);
                      new google.maps.Marker( {
                          map:map,position:r[0].geometry.location,title:'Dapur Kereenak',label:'🍱'
                      });
                  }
              });
              g('cari').hidden=false;
              g('kn').textContent='Klik peta atau cari lokasi untuk memilih tujuan, atau ketik alamat di bawah.';
              var ac=new google.maps.places.Autocomplete(g('cari'), {
                  componentRestrictions: {
                      country:'id'
                  }
              });
              ac.addListener('place_changed',function() {
                  var p=ac.getPlace();
                  if(p.geometry)choose(p.geometry.location,(p.name?p.name+', ':'')+(p.formatted_address||''));
              });
              map.addListener('click',function(e) {
                  gc.geocode( {
                      location:e.latLng
                  },function(r,st) {
                      choose(e.latLng,st=='OK'&&r[0]?r[0].formatted_address:'');
                  });
              });
              ['alamat','campus'].forEach(function(i) {
                  g(i).addEventListener('change',function() {
                      var t=(g('alamat').value+' '+g('campus').value).trim();
                      if(t)gc.geocode( {
                          address:t+', Indonesia'
                      },function(r,st) {
                          if(st=='OK')choose(r[0].geometry.location,'');
                      });
                  });
              });
          };
          document.querySelectorAll('[name=bayar]').forEach(function(e) {
              e.addEventListener('change',pay);
          });
          g('bukti').addEventListener('change',function() {
              var f=this.files[0];
              if(f) {
                  g('prev').src=URL.createObjectURL(f);
                  g('prev').hidden=false;
              }
          });
          g('alamat').addEventListener('input',function() {
              this.dataset.auto='0';
          });
          if(KEY) {
              var sc=document.createElement('script');
              sc.src='https://maps.googleapis.com/maps/api/js?key='+KEY+'&libraries=places&callback=initPick';
              document.head.appendChild(sc);
          } else {
              g('kn').textContent='Rute dari dapur tampil sesuai alamat yang kamu ketik.';
              ['alamat','campus'].forEach(function(i) {
                  g(i).addEventListener('change',frame);
              });
              frame();
          }
      </script>
      <script>
          (function() {
              var box=document.getElementById('sumdyn'),busy=false;
              box.addEventListener('click',function(e) {
                  var b=e.target.closest('button[data-d]');
                  if(!b||b.disabled||busy)return;
                  busy=true;
                  box.style.opacity='.55';
                  fetch('?a=upd&ajax=1&sum=1&id='+b.dataset.id+'&d='+b.dataset.d).then(function(r) {
                      return r.json();
                  }).then(function(j) {
                      document.getElementById('cc').textContent=j.n;
                      if(!j.n) {
                          location.reload();
                          return;
                      }
                      box.innerHTML=j.html;
                  }).catch(function() {
                      location.reload();
                  }).then(function() {
                      busy=false;
                      box.style.opacity='';
                  });
              });
          })();
      </script>
    <?php endif;?>
  </div>
<?php elseif($p=='status'):?>
  <?php
  $o=$db->prepare("SELECT * FROM orders WHERE kode=?");
  $o->execute([$_GET['k']??'']);
  $o=$o->fetch(PDO::FETCH_ASSOC);
  if(!$o)exit('Pesanan tidak ditemukan');
  $step=(int)$o['step'];
  $kd=urlencode($o['kode']);
  $pl=PLAN[$o['jenis']]??['Sekali Antar'];
  $dest=trim($o['alamat'].' '.$o['campus']);
  $ll=is_numeric($o['lat'])?['lat'=>(float)$o['lat'],'lng'=>(float)$o['lng']]:null;
  $its=$db->query("SELECT i.*,m.img FROM items i LEFT JOIN menu m ON m.name=i.name WHERE i.oid=".(int)$o['id'])->fetchAll(PDO::FETCH_ASSOC);
  $tl=[['Pesanan Diterima','Dapur mengonfirmasi pesanan'],['Sedang Dimasak','Makanan sedang disiapkan'],['Diantar ke Alamat','Kurir sedang menuju alamat'],['Sudah Sampai','Pesanan telah tiba']];
  $hd=['Makan siangmu sedang disiapkan.','Makan siangmu sedang dimasak.','Makan siangmu sedang diantar.','Makan siangmu sudah sampai!','Makan siangmu sudah sampai!'];
  ?>
  <div class="wrap">
    <div class="sph">
      <div>
        <div class="eye o">Status pesanan</div>
        <h1><?=$hd[$step]?></h1>
      </div>
      <div class="kode"><small>KODE PESANAN</small><b><?=h($o['kode'])?></b><span style="font-size:12px"><?=h($pl[0])?></span></div>
    </div>
    <div class="spg">
      <div class="card">
        <div class="top">
          <h3>Perjalanan pesanan</h3>
          <small>DIPERBARUI LANGSUNG</small></div>
        <div class="tls">
        <?php foreach($tl as $i=>$t):?>
          <?php $c=($i<$step||$step>=3)?'done':($i==$step?'now':'todo'); ?>
          <div class="tl <?=$c?>"><span class="tc"><?=$c=='done'?'✓':ICON[$i]?></span>
            <div><b><?=$t[0]?></b><small><?=$t[1]?></small></div>
            <?php if($c=='now'):?>
              <em class="pillnow">SEKARANG</em>
            <?php endif;?>
          </div>
        <?php endforeach;?>
        </div>
        <?php if($step<3):?>
          <p class="hint" style="margin-top:6px">Status diperbarui oleh admin. Halaman ini menyegarkan diri otomatis.</p>
        <?php elseif($o['rating']>0):?>
          <div class="rt"><b>Terima kasih atas ratingmu!</b>
            <div class="stars ro"><?=str_repeat('★',$o['rating'])?></div>
            <p><?=h($o['ulasan'])?></p>
          </div>
        <?php else:?>
          <form class="rt" method="post" action="?a=rate" onsubmit="if(!+document.getElementById('rv').value){alert('Pilih bintang dulu ya');return false}">
            <b>Bagaimana makananmu?</b><input type="hidden" name="kode" value="<?=h($o['kode'])?>"><input type="hidden" name="rating" id="rv" value="0">
            <div class="stars" id="stars">
              <?php foreach(range(1,5) as $i):?>
                <span data-v="<?=$i?>">★</span>
              <?php endforeach;?>
            </div>
            <small class="hint" id="rl">Ketuk bintang untuk menilai</small>
            <textarea name="ulasan" placeholder="Ceritakan pengalamanmu (opsional)"></textarea><button class="btn dk">Kirim rating</button></form>
        <?php endif;?>
      </div>
      <div class="card">
        <h3 style="margin-bottom:20px">Detail pesanan</h3>
        <div class="dr"><span>Jenis layanan</span><b><?=h($pl[0])?></b></div>
        <div class="dr"><span>Tanggal antar</span><b><?=idtgl($o['tgl'])?></b></div>
        <div class="dr"><span>Pembayaran</span><b><?=PAY[$o['bayar']][0]??'-'?></b></div>
        <div class="dr"><span>Alamat penyedia</span><b><?=PROV_NAME?><small><?=PROV_ST?></small></b></div>
        <div class="dr"><span>Tujuan</span><b><?=h($o['campus'])?><small><?=h($o['alamat'])?><?=$o['catatan']?' · '.h($o['catatan']):''?></small></b></div>
        <small class="lbl">MENU DIPESAN</small>
        <?php foreach($its as $i):?>
          <div class="mi"><?=$i['img']?'<img class="th2" src="'.h($i['img']).'" alt="">':'<div class="th2">🍛</div>'?><span><?=h($i['name'])?></span><em>× <?=$i['qty']?></em></div>
        <?php endforeach;?>
      </div>
    </div>
    <div class="card">
      <div class="top">
        <h3>Lacak kurir</h3>
        <small>RUTE GOOGLE MAPS</small></div>
      <div id="map"></div>
      <div class="cr"><span style="font-size:30px">🛵</span>
        <div style="flex:1"><b><?=COURIER_NAME?></b> · <?=COURIER_PLATE?><br><small id="eta"></small>
          <div class="cprog"><i id="cp"></i></div>
        </div>
      </div>
    </div>
  </div>
  <script>
      var STEP=<?=$step?>,ORG='<?=h(ORIGIN)?>',KEY='<?=GMAPS_KEY?>',DEST=<?=json_encode($dest)?>,LL=<?=json_encode($ll)?>,prog=STEP>=3?1:0,cm=null,path=null,mins=12;
      function g(i) {
          return document.getElementById(i);
      }
      function at(t) {
          return path?path[Math.min(path.length-1,Math.floor(t*(path.length-1)))]:null;
      }
      function ui() {
          g('cp').style.width=Math.round(prog*100)+'%';
          g('eta').textContent=STEP>=3?'Kurir sudah sampai di tujuan':STEP==2?'Estimasi tiba ±'+Math.max(1,Math.round(mins*(1-prog)))+' menit':STEP==1?'Kurir menunggu makanan matang':'Kurir bersiap di dapur';
      }
      function tick() {
          if(STEP==2&&prog<0.97)prog+=0.02;
          ui();
          var p=at(prog);
          if(cm&&p)cm.setPosition(p);
      }
      window.initMap=function() {
          var map=new google.maps.Map(g('map'), {
              zoom:14,streetViewControl:false,mapTypeControl:false
          });
          var dr=new google.maps.DirectionsRenderer( {
              map:map,suppressMarkers:true,polylineOptions: {
                  strokeColor:'#173F2D',strokeWeight:5
              }
          });
          new google.maps.DirectionsService().route( {
              origin:ORG,destination:LL||DEST,travelMode:'DRIVING'
          },function(r,st) {
              if(st!='OK') {
                  g('eta').textContent='Rute tidak ditemukan, periksa alamat tujuan';
                  return;
              }
              dr.setDirections(r);
              var lg=r.routes[0].legs[0];
              path=r.routes[0].overview_path;
              mins=Math.max(1,Math.round(lg.duration.value/60));
              new google.maps.Marker( {
                  map:map,position:lg.start_location,title:'Dapur Kereenak',label:'🍱'
              });
              new google.maps.Marker( {
                  map:map,position:lg.end_location,title:'Tujuan',label:'📍'
              });
              cm=new google.maps.Marker( {
                  map:map,position:at(prog),title:'Kurir',label:'🛵',zIndex:9
              });
              ui();
          });
      };
      if(KEY) {
          var sc=document.createElement('script');
          sc.src='https://maps.googleapis.com/maps/api/js?key='+KEY+'&callback=initMap';
          document.head.appendChild(sc);
      } else {
          g('map').innerHTML='<iframe class="mapf" src="https://maps.google.com/maps?saddr='+encodeURIComponent(ORG)+'&daddr='+encodeURIComponent(LL?LL.lat+','+LL.lng:DEST)+'&output=embed"></iframe>';
      }
      ui();
      setInterval(tick,1000);
      if(STEP<3)setTimeout(function(){location.reload()},15000);
      var sw=g('stars'),cur=0;
      function paint(v) {
          [].forEach.call(sw.children,function(x,i) {
              x.className=i<v?'on':'';
          });
      }
      if(sw) {
          sw.addEventListener('click',function(e) {
              var v=+e.target.dataset.v;
              if(!v)return;
              cur=v;
              g('rv').value=v;
              paint(v);
              g('rl').textContent=v+' dari 5 bintang';
          });
          sw.addEventListener('mouseover',function(e) {
              var v=+e.target.dataset.v;
              if(v)paint(v);
          });
          sw.addEventListener('mouseleave',function() {
              paint(cur);
          });
      }
  </script>
</div>
<?php elseif($p=='struk'):?>
<?php
$o=$db->query("SELECT * FROM orders WHERE id=".(int)($_GET['id']??0))->fetch(PDO::FETCH_ASSOC);
if(!$o)exit('Pesanan tidak ditemukan');
$its=$db->query("SELECT * FROM items WHERE oid=".(int)$o['id'])->fetchAll(PDO::FETCH_ASSOC);
$sb=$o['total']-$o['ongkir']+$o['diskon'];
?>
<div class="wrap">
  <div class="noprint">
    <div class="eye o">Struk pesanan</div>
    <h1>Cetak struk.</h1>
    <div class="tools"><a class="btn dk" href="?p=admin&t=pes">← Kembali</a><a class="btn" href="#" onclick="window.print();return false">🖨 Cetak struk</a></div>
  </div>
  <div class="struk">
    <div class="logo" style="font-size:30px;text-align:center;color:var(--g)">kere<b>enak</b><i>.</i></div>
    <p class="sc"><?=PROV_ADDR?></p>
    <hr>
    <div class="sl"><span>Kode</span><b><?=h($o['kode']?:'#'.$o['id'])?></b></div>
    <div class="sl"><span>Tanggal pesan</span><b><?=h($o['at'])?></b></div>
    <div class="sl"><span>Pelanggan</span><b><?=h($o['name'])?></b></div>
    <div class="sl"><span>WhatsApp</span><b><?=h($o['phone'])?></b></div>
    <div class="sl"><span>Antar tanggal</span><b><?=idtgl($o['tgl'])?></b></div>
    <div class="sl"><span>Tujuan</span><b><?=h($o['campus'])?><br><?=h($o['alamat'])?></b></div>
    <div class="sl"><span>Pembayaran</span><b><?=h(PAY[$o['bayar']][0]??'-')?></b></div>
    <div class="sl"><span>Status</span><b><?=h($o['status'])?></b></div>
    <hr>
    <?php foreach($its as $i):?>
      <div class="sl"><span><?=h($i['name'])?> × <?=$i['qty']?></span><b><?=rp($i['price']*$i['qty'])?></b></div>
    <?php endforeach;?>
    <hr>
    <div class="sl"><span>Subtotal</span><b><?=rp($sb)?></b></div>
    <?php if($o['diskon']>0):?>
      <div class="sl"><span>Diskon</span><b>-<?=rp($o['diskon'])?></b></div>
    <?php endif;?>
    <div class="sl"><span>Ongkir</span><b><?=rp($o['ongkir'])?></b></div>
    <div class="sl t"><span>TOTAL</span><b><?=rp($o['total'])?></b></div>
    <hr>
    <p class="sc">Terima kasih sudah belanja di kami!<br>Kere boleh, lapar jangan 🍛</p>
  </div>
</div>
<?php elseif($p=='admin'):?>
<div class="wrap">
  <?php if(!$adm):?>
    <div class="lgw">
      <form method="post" action="?p=admin&a=login" class="card lgc">
        <div class="eye o">Panel admin</div>
        <h2>Masuk</h2>
        <?php if(!admin_set()):?><div class="err">Password admin belum diatur di config.php</div><?php elseif($a=='login'):?><div class="err">Password salah</div><?php endif;?>
        <label class="fl" style="margin-top:0">Password superadmin</label>
        <input type="password" name="pw" required autofocus>
        <button class="btn big">Masuk</button>
      </form>
    </div>
  <?php else:?>
    <div class="eye o">Panel admin</div>
    <h1>Kelola Kereenak.</h1>
    <?php
    $t=$_GET['t']??($_SESSION['t']??'ring');
    $_SESSION['t']=$t;
    $q1=fn($sql)=>(int)$db->query($sql)->fetchColumn();
    $jual=$q1("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='Selesai'");
    $kin=$q1("SELECT COALESCE(SUM(jumlah),0) FROM kas WHERE tipe='masuk'");
    $out=$q1("SELECT COALESCE(SUM(jumlah),0) FROM kas WHERE tipe='keluar'");
    $tin=$jual+$kin;
    $baru=$q1("SELECT COUNT(*) FROM orders WHERE status='Baru'");
    $tabs=['ring'=>'Ringkasan','menu'=>'Menu & Stok','pes'=>'Pesanan','kas'=>'Keuangan'];
    $stl=['Pesanan Diterima','Sedang Dimasak','Diantar ke Alamat','Sudah Sampai'];
    ?>
    <div class="tabs">
      <?php foreach($tabs as $k=>$v):?>
        <a href="?p=admin&t=<?=$k?>" class="<?=$t==$k?'on':''?>"><?=$v?></a>
      <?php endforeach;?>
      <a href="?a=logout" class="out" style="margin-left:auto">Keluar</a></div>
    <?php if($t=='ring'||$t=='kas'):?>
      <div class="stats">
        <div class="stat"><small>Uang masuk</small><b class="in"><?=rp($tin)?></b></div>
        <div class="stat"><small>Uang keluar</small><b class="out"><?=rp($out)?></b></div>
        <div class="stat"><small>Saldo</small><b><?=rp($tin-$out)?></b></div>
        <div class="stat"><small>Pesanan baru</small><b><?=$baru?></b></div>
      </div>
    <?php endif;?>
    <?php if($t=='ring'):?>
      <div class="card">
        <h3 style="margin-bottom:16px">Stok menipis / habis (≤ 5)</h3>
        <table>
          <?php foreach($db->query("SELECT * FROM menu WHERE stok<=5 ORDER BY stok") as $m):?>
            <tr><td><?=h($m['name'])?></td><td><?=$m['stok']<=0?'<span class="pill Batal">Habis</span>':'Sisa '.$m['stok']?></td></tr>
          <?php endforeach;?>
        </table>
      </div>
      <?php $rr=$db->query("SELECT COUNT(*),AVG(rating) FROM orders WHERE rating>0")->fetch(PDO::FETCH_NUM); ?>
      <div class="card">
        <h3 style="margin-bottom:16px">Rating pembeli</h3>
        <?php if($rr[0]):?>
          <p style="margin:0 0 10px"><b class="hd" style="font-size:28px">★ <?=number_format($rr[1],1)?></b> &nbsp;dari <?=$rr[0]?> ulasan</p>
          <table>
            <?php foreach($db->query("SELECT kode,name,rating,ulasan FROM orders WHERE rating>0 ORDER BY id DESC LIMIT 5") as $r):?>
              <tr><td><b><?=h($r['kode'])?></b> · <?=h($r['name'])?></td><td style="color:#F4B400"><?=str_repeat('★',$r['rating'])?></td><td><?=h($r['ulasan'])?></td></tr>
            <?php endforeach;?>
          </table>
        <?php else:?>
          <p style="margin:0;color:#7a8a80">Belum ada rating dari pembeli.</p>
        <?php endif;?>
      </div>
      <div class="card">
        <h3 style="margin-bottom:16px">Pesanan terbaru</h3>
        <table>
          <?php foreach($db->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5") as $o):?>
            <tr><td><b><?=h($o['kode']?:'#'.$o['id'])?></b> · <?=h($o['name'])?></td><td><?=rp($o['total'])?></td><td><span class="pill <?=h($o['status'])?>"><?=h($o['status'])?></span></td><td><a class="lnk" href="?p=struk&id=<?=$o['id']?>">Struk</a></td></tr>
          <?php endforeach;?>
        </table>
      </div>
    <?php elseif($t=='menu'):?>
      <form method="post" action="?a=save" enctype="multipart/form-data" class="card">
        <h3 style="margin-bottom:18px">Tambah makanan</h3>
        <div class="fg">
          <div><label class="fl">Nama</label><input name="name" required></div>
          <div><label class="fl">Deskripsi</label><input name="descr"></div>
          <div><label class="fl">Harga (Rp)</label><input type="number" name="price" required></div>
          <div><label class="fl">Stok awal</label><input type="number" name="stok" value="0" required></div>
          <div><label class="fl">Badge (opsional)</label><input name="badge"></div>
          <div><label class="fl">Foto</label><input type="file" name="img" accept="image/*"></div>
        </div>
        <button class="btn">Simpan makanan</button></form>
      <div class="card">
        <h3 style="margin-bottom:8px">Daftar menu, harga &amp; stok</h3>
        <?php foreach($db->query("SELECT * FROM menu ORDER BY id") as $m):?>
          <form class="rw" method="post" action="?a=upd_menu" enctype="multipart/form-data"><input type="hidden" name="id" value="<?=$m['id']?>"><?=$m['img']?'<img class="th2" src="'.h($m['img']).'" alt="">':'<div class="th2">🍛</div>'?>
            <div class="nm"><b><?=h($m['name'])?></b><br><small><?=h($m['descr'])?></small></div>
            Harga<input type="number" name="price" value="<?=$m['price']?>">Stok<input type="number" name="stok" value="<?=$m['stok']?>" style="width:80px"><input type="file" name="img" accept="image/*">
            <button class="btn sm">Update</button><a class="lnk out" href="?a=del&id=<?=$m['id']?>" onclick="return confirm('Hapus menu ini?')">Hapus</a></form>
        <?php endforeach;?>
      </div>
    <?php elseif($t=='pes'):?>
      <?php foreach($db->query("SELECT * FROM orders ORDER BY id DESC") as $o):?>
        <?php $rute='https://www.google.com/maps/dir/?api=1&origin='.urlencode(ORIGIN).'&destination='.urlencode(is_numeric($o['lat'])?$o['lat'].','.$o['lng']:$o['alamat'].' '.$o['campus']); ?>
        <div class="card">
          <div class="oh">
            <div><b class="ok"><?=h($o['kode']?:'#'.$o['id'])?></b><small><?=h($o['at'])?></small></div>
            <span class="pill <?=h($o['status'])?>"><?=h($o['status'])?></span></div>
          <div class="og">
            <div><small class="lbl">PELANGGAN</small><b><?=h($o['name'])?></b><span><?=h($o['phone'])?></span></div>
            <div><small class="lbl">TUJUAN</small><b><?=h($o['campus'])?></b><span><?=h($o['alamat'])?><?=$o['catatan']?' · Catatan: '.h($o['catatan']):''?></span><a class="lnk" href="<?=h($rute)?>" target="_blank">Lihat rute ↗</a></div>
            <div><small class="lbl">JADWAL &amp; BAYAR</small><b><?=idtgl($o['tgl'])?></b><span><?=h(PAY[$o['bayar']][0]??'-')?></span>
              <?php if($o['bukti']):?>
                <a class="lnk" href="?a=bukti&id=<?=$o['id']?>" target="_blank">Bukti bayar ↗</a>
              <?php endif;?>
            </div>
            <div><small class="lbl">TAHAP</small><b><?=$stl[min(3,(int)$o['step'])]?></b><span><?=$o['rating']?'★'.$o['rating'].' '.h($o['ulasan']):'Rating diisi pembeli'?></span></div>
          </div>
          <table>
            <?php foreach($db->query("SELECT * FROM items WHERE oid=".(int)$o['id']) as $i):?>
              <tr><td><?=h($i['name'])?> × <?=$i['qty']?></td><td><?=rp($i['price']*$i['qty'])?></td></tr>
            <?php endforeach;?>
            <tr><td><b>Total (termasuk ongkir)</b></td><td><b><?=rp($o['total'])?></b></td></tr>
          </table>
          <?php $sp=min(3,(int)$o['step']); ?>
          <div class="stpa">
            <div class="chips"><?php foreach($stl as $i=>$n):?><span class="chip <?=$i<=$sp?'on':''?>"><?=$n?></span><?php endforeach;?></div>
            <?php if($o['status']!='Batal'):?>
              <?php if($sp>0):?><a class="lnk" href="?a=step&id=<?=$o['id']?>&d=-1">← Mundur</a><?php endif;?>
              <?php if($sp<3):?><a class="btn sm" href="?a=step&id=<?=$o['id']?>&d=1">Lanjut: <?=$stl[$sp+1]?> →</a><?php endif;?>
            <?php endif;?>
          </div>
          <div class="oa"><a class="btn sm" href="?p=struk&id=<?=$o['id']?>">🧾 Struk</a>
            <?php if($o['status']=='Baru'):?>
              <a class="btn sm ok2" href="?a=status&id=<?=$o['id']?>&s=Selesai">✓ Tandai selesai (uang masuk)</a><a class="lnk out" href="?a=status&id=<?=$o['id']?>&s=Batal" onclick="return confirm('Batalkan pesanan? Stok dikembalikan.')">✕ Batalkan</a>
            <?php endif;?>
          </div>
        </div>
      <?php endforeach;?>
    <?php elseif($t=='kas'):?>
      <form method="post" action="?a=kas" class="card">
        <h3 style="margin-bottom:18px">Catat transaksi</h3>
        <div class="fg">
          <div><label class="fl">Jenis</label><select name="tipe"><option value="keluar">Uang keluar</option><option value="masuk">Uang masuk lain</option></select></div>
          <div><label class="fl">Jumlah (Rp)</label><input type="number" name="jumlah" required></div>
        </div>
        <label class="fl">Keterangan (belanja bahan, gaji, modal, dll)</label><input name="ket" required><button class="btn">Simpan</button></form>
      <div class="card">
        <h3 style="margin-bottom:8px">Riwayat uang masuk &amp; keluar</h3>
        <table>
          <tr><th>Tanggal</th><th>Keterangan</th><th>Jumlah</th><th></th></tr>
          <?php foreach($db->query("SELECT \"at\",CONCAT('Pesanan #',id) AS ket,total AS jumlah,'masuk' AS tipe,0 AS kid FROM orders WHERE status='Selesai' UNION ALL SELECT \"at\",ket,jumlah,tipe,id FROM kas ORDER BY \"at\" DESC") as $k):?>
            <tr><td><?=h($k['at'])?></td><td><?=h($k['ket'])?></td><td class="<?=$k['tipe']=='masuk'?'in':'out'?>"><?=$k['tipe']=='masuk'?'+':'−'?> <?=rp($k['jumlah'])?></td><td>
              <?php if($k['kid']):?>
                <a class="lnk out" href="?a=kasdel&id=<?=$k['kid']?>" onclick="return confirm('Hapus?')">Hapus</a>
              <?php endif;?>
              </td></tr>
          <?php endforeach;?>
        </table>
      </div>
    <?php endif;?>
  <?php endif;?>
</div>
<?php endif;?>
</body>
</html>
