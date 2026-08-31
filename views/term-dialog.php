<?php
$termPresets = nu_academic_presets();
$termYears = array_keys($termPresets);
rsort($termYears, SORT_NUMERIC);
$currentYear = (int)date('Y')+543;
$selectedYear = (int)($termInput['academic_year'] ?? (isset($termPresets[$currentYear]) ? $currentYear : $termYears[0]));
$selectedSemester = (string)($termInput['semester'] ?? '1');
if ($selectedSemester === '3') $selectedSemester = 'summer';
$presetYear = $termPresets[$selectedYear] ?? null;
$presetDates = $presetYear['terms'][$selectedSemester] ?? null;
$termInput = ['academic_year'=>$selectedYear, 'semester'=>$selectedSemester];
?>
<dialog id="term-settings" class="term-dialog" aria-labelledby="term-dialog-title" aria-describedby="term-dialog-description" <?= isset($_GET['new_term']) || $termErrors ? 'open' : '' ?>>
    <header class="term-dialog__header">
        <div><h2 id="term-dialog-title">เพิ่มภาคการศึกษา</h2><p id="term-dialog-description">เลือกปีและภาค วันที่กำหนดตามปฏิทิน ม.นเรศวร</p></div>
        <a class="icon-button" href="<?= e($termReturnUrl) ?>" data-close-term aria-label="ปิดหน้าต่างเพิ่มภาคการศึกษา"><span data-icon="x"></span></a>
    </header>
    <form method="post" action="<?= e($termReturnUrl) ?>" data-term-form novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_term">
        <div class="term-dialog__body">
            <?php if ($termErrors): ?><div class="alert alert--error term-error-summary" role="alert" tabindex="-1" data-term-error-summary><strong>ยังไม่ได้บันทึกภาคการศึกษา</strong><span><?= e($termErrors['form'] ?? 'กรุณาแก้ไขข้อมูลด้านล่าง แล้วบันทึกอีกครั้ง') ?></span></div><?php endif; ?>
            <p class="term-required-note">หนึ่งปีมี 3 ภาค: ภาคต้น ภาคปลาย และฤดูร้อน</p>
            <div class="form-grid">
                <?php foreach (['academic_year'=>'ปีการศึกษา (พ.ศ.)', 'semester'=>'ภาคการศึกษา'] as $name=>$label): ?>
                    <div class="field"><label for="term-<?= e($name) ?>"><?= e($label) ?></label>
                        <select id="term-<?= e($name) ?>" name="<?= e($name) ?>" required aria-describedby="error-<?= e($name) ?>" <?= isset($termErrors[$name]) ? 'aria-invalid="true"' : '' ?>>
                            <?php if (($name === 'academic_year' && !$presetYear) || ($name === 'semester' && !isset(semester_labels()[$selectedSemester]))): ?><option value="" selected disabled>กรุณาเลือก<?= $name === 'academic_year' ? 'ปีการศึกษา' : 'ภาคการศึกษา' ?></option><?php endif; ?>
                            <?php $options = $name === 'academic_year' ? array_combine($termYears, $termYears) : semester_labels(); foreach ($options as $value=>$text): ?><option value="<?= e($value) ?>" <?= (string)$termInput[$name] === (string)$value ? 'selected' : '' ?>><?= e($text) ?></option><?php endforeach; ?>
                        </select><span id="error-<?= e($name) ?>" class="field-error" <?= isset($termErrors[$name]) ? '' : 'hidden' ?>><?= e($termErrors[$name] ?? '') ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="term-code-preview field--full"><span>รหัสภาคการศึกษา</span><output data-term-code><?= $presetDates ? e(academic_term_code($selectedYear, $selectedSemester)) : '—' ?></output><small data-term-exists></small></div>
                <?php foreach (['start'=>'วันเปิดเรียน', 'end'=>'วันสิ้นสุดภาค (รวมสอบ)'] as $key=>$label): ?>
                    <div class="field term-fixed-date"><label for="term-<?= e($key) ?>"><?= e($label) ?></label><output id="term-<?= e($key) ?>" data-term-<?= e($key) ?> aria-live="polite"><?= $presetDates ? e(thai_date_label($presetDates[$key])) : '—' ?></output></div>
                <?php endforeach; ?>
                <p class="helper-text field--full">วันที่กำหนดไว้แล้วสำหรับปริญญาตรี ภาคปกติ ไม่ต้องกรอกหรือยืนยันเอง<br>เลือกได้เฉพาะปีที่มีปฏิทินในระบบ ตารางเรียนรายวิชากำหนดช่วงสั้นกว่าภาคได้</p>
                <div class="inline-note field--full" data-term-source aria-live="polite"><?php if ($presetDates): ?><a href="<?= e($presetDates['source'] ?? $presetYear['source']) ?>" target="_blank" rel="noopener"><?= e($presetYear['source_label']) ?></a><?php else: ?>เลือกปีและภาคเพื่อแสดงวันที่<?php endif; ?></div>
            </div>
        </div>
        <footer class="term-dialog__actions"><a class="button button--secondary" href="<?= e($termReturnUrl) ?>" data-close-term>ยกเลิก</a><button class="button button--primary" type="submit">บันทึกภาคการศึกษา</button></footer>
    </form>
    <script type="application/json" data-term-presets><?= json_encode($termPresets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="application/json" data-existing-terms><?= json_encode(array_map(static fn(array $t): array => ['year'=>$t['academic_year'], 'semester'=>$t['semester']], $terms), JSON_HEX_TAG) ?></script>
</dialog>
