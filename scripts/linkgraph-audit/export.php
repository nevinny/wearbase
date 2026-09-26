<?php
// Выгрузка TSV через PDO из DATABASE_* в .env.local; argv[1]=SQL
$env=[];foreach(file('.env.local') as $l){if(preg_match('/^([A-Z_]+)=(.*)$/',trim($l),$m))$env[$m[1]]=trim($m[2],'"\'');}
$pdo=new PDO("mysql:host={$env['DATABASE_HOST']};dbname={$env['DATABASE_NAME']};charset=utf8mb4",$env['DATABASE_USER'],$env['DATABASE_PASSWORD']);
$st=$pdo->query($argv[1]);$h=false;
while($r=$st->fetch(PDO::FETCH_ASSOC)){if(!$h){echo implode("\t",array_keys($r)),"\n";$h=true;}echo implode("\t",array_map(fn($v)=>str_replace(["\t","\n"],' ',(string)$v),$r)),"\n";}
