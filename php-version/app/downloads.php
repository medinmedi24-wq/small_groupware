<?php
declare(strict_types=1);
function downloadReceipt(array $u,string $id,?string $attachment=null): never {
    $r=one('SELECT * FROM `CardExpense` WHERE id=?',[$id]);
    if(!$r||!$r['receiptFilePath']||$u['role']!=='ADMIN'&&$r['userId']!==$u['id'])throw new AppError('증빙 파일을 찾을 수 없습니다.',404);
    $selected=null;
    foreach(cardReceipts($r) as $proof)if($attachment===null||$proof['receiptFilePath']===$attachment){$selected=$proof;break;}
    if(!$selected)throw new AppError('증빙 파일을 찾을 수 없습니다.',404);
    $r=$selected;
    $file=storage('receipts'.DIRECTORY_SEPARATOR.basename($r['receiptFilePath']));
    if(!is_file($file))throw new AppError('증빙 파일을 찾을 수 없습니다.',404);
    header('Content-Type: application/octet-stream');header("Content-Disposition: attachment; filename=receipt; filename*=UTF-8''".rawurlencode($r['receiptFileName']?:'receipt'));header('Content-Length: '.filesize($file));readfile($file);exit;
}
function xml(string $v): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$v)??'',ENT_XML1|ENT_QUOTES,'UTF-8'); }
function writeReport(int $year,string $target): void {
    if(!class_exists(ZipArchive::class))throw new AppError('엑셀 생성에 필요한 PHP zip 확장이 활성화되지 않았습니다.',503);
    $data=rows('SELECT l.*,u.name,u.department,u.position FROM `LeaveRequest` l JOIN `User` u ON l.userId=u.id WHERE l.isDeleted=0 AND l.startDate>=? AND l.startDate<? ORDER BY l.startDate,u.name',["$year-01-01",($year+1).'-01-01']);
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="4" width="19" customWidth="1"/><col min="5" max="6" width="15" customWidth="1"/><col min="7" max="7" width="10" customWidth="1"/><col min="8" max="8" width="50" customWidth="1"/><col min="9" max="9" width="22" customWidth="1"/></cols><sheetData>';
    $sheet.='<row r="1" ht="36" customHeight="1"><c r="A1" t="inlineStr" s="1"><is><t>'.xml("{$year}년 연차 사용 내역").'</t></is></c></row>';
    $records=[['이름','팀','직급','종류','시작','종료','일수','사유','상태']];
    foreach($data as $r)$records[]=[$r['name'],$r['department'],$r['position'],LEAVE_TYPES[$r['leaveType']]??$r['leaveType'],substr($r['startDate'],0,10),substr($r['endDate'],0,10),(float)$r['days'],$r['reason'],STATUSES[$r['status']]??$r['status']];
    foreach($records as $i=>$record){$n=$i+3;$sheet.='<row r="'.$n.'">';foreach($record as $j=>$v){$ref=chr(65+$j).$n;$style=$i===0?2:0;if(is_float($v)||is_int($v))$sheet.='<c r="'.$ref.'" s="'.$style.'"><v>'.$v.'</v></c>';else $sheet.='<c r="'.$ref.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.xml((string)$v).'</t></is></c>';}$sheet.='</row>';}
    $sheet.='</sheetData><autoFilter ref="A3:I'.(count($records)+2).'"/><mergeCells count="1"><mergeCell ref="A1:I1"/></mergeCells></worksheet>';
    $zip=new ZipArchive();if($zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Report storage unavailable');
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="연차 사용 내역" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml','<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Malgun Gothic"/></font><font><b/><sz val="18"/><name val="Malgun Gothic"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDDDDD"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf><xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);if(!$zip->close())throw new RuntimeException('Report write failed');
}
function downloadReport(array $u,int $year): never {
    admin($u);$dir=storage('reports');if(!is_dir($dir))mkdir($dir,0700,true);$target=tempnam($dir,'xlsx-');
    if($target===false)throw new RuntimeException('Report storage unavailable');
    try{writeReport($year,$target);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header("Content-Disposition: attachment; filename=leave-report.xlsx; filename*=UTF-8''".rawurlencode("MNM-WORKS_{$year}년_휴가현황.xlsx"));header('Content-Length: '.filesize($target));readfile($target);}finally{@unlink($target);}exit;
}
