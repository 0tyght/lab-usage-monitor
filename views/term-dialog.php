<?php
$selectedYear = (int)($termInput['academic_year'] ?? date('Y')+543);
$selectedSemester = (string)($termInput['semester'] ?? '1');
$presetYear = nu_academic_presets()[$selectedYear] ?? null;
$presetDates = $presetYear['terms'][$selectedSemester] ?? null;
$termInput += ['academic_year'=>$selectedYear, 'semester'=>$selectedSemester, 'term_starts_on'=>$presetDates['start'] ?? '', 'term_ends_on'=>$presetDates['end'] ?? ''];
$termYears = range((int)date('Y')+548, 2500);
if (!in_array($selectedYear, $termYears, true)) $termYears[] = $selectedYear;
?>
<dialog id="term-settings" class="term-dialog" aria-labelledby="term-dialog-title" aria-describedby="term-dialog-description" <?= isset($_GET['new_term']) || $termErrors ? 'open' : '' ?>>
    <header class="term-dialog__header">
        <div><h2 id="term-dialog-title">เพิ่มภาคการศึกษา</h2><p id="term-dialog-description">เลือกปีและภาค ระบบตั้งชื่อให้อัตโนมัติ เช่น 2569/1</p></div>
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
                            <?php $options = $name === 'academic_year' ? array_combine($termYears, $termYears) : semester_labels(); foreach ($options as $value=>$text): ?><option value="<?= e($value) ?>" <?= (string)$termInput[$name] === (string)$value ? 'selected' : '' ?>><?= e($text) ?></option><?php endforeach; ?>
                        </select><span id="error-<?= e($name) ?>" class="field-error" <?= isset($termErrors[$name]) ? '' : 'hidden' ?>><?= e($termErrors[$name] ?? '') ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="term-code-preview field--full"><span>รหัสภาคการศึกษา</span><output data-term-code><?= e(academic_term_code($selectedYear, $selectedSemester)) ?></output><small data-term-exists></small></div>
                <div class="inline-note field--full" data-term-source aria-live="polite"><?php if ($presetYear): ?><a href="<?= e($presetYear['source']) ?>" target="_blank" rel="noopener"><?= e($presetYear['source_label']) ?></a><?php else: ?>ยังไม่มีวันที่อ้างอิงที่ยืนยันแล้วสำหรับปีนี้ กรุณาตรวจประกาศของมหาวิทยาลัยก่อนกรอก<?php endif; ?></div>
                <?php foreach (['term_starts_on'=>'วันเปิดเรียน', 'term_ends_on'=>'วันสิ้นสุดภาค (รวมสอบ)'] as $name=>$label): ?>
                    <div class="field"><label for="term-<?= e($name) ?>"><?= e($label) ?></label><input id="term-<?= e($name) ?>" type="date" name="<?= e($name) ?>" value="<?= e($termInput[$name]) ?>" required aria-describedby="error-<?= e($name) ?>" <?= isset($termErrors[$name]) ? 'aria-invalid="true"' : '' ?>><span id="error-<?= e($name) ?>" class="field-error" <?= isset($termErrors[$name]) ? '' : 'hidden' ?>><?= e($termErrors[$name] ?? '') ?></span></div>
                <?php endforeach; ?>
                <p class="helper-text field--full">ช่วงวันที่รวมการสอบและสิ้นสุดก่อนช่วงปิดภาค 1 วัน ตารางเรียนประจำวิชาอาจสิ้นสุดก่อนหน้านี้ได้</p>
                <div class="field field--full"><label class="checkbox-field"><input type="checkbox" name="dates_confirmed" value="1" <?= ($termInput['dates_confirmed'] ?? '') === '1' ? 'checked' : '' ?> aria-describedby="error-dates_confirmed"><span>ตรวจสอบวันที่กับประกาศของปี/หลักสูตรที่ใช้งานแล้ว (จำเป็นเมื่อกำหนดวันที่เอง)</span></label><span id="error-dates_confirmed" class="field-error" <?= isset($termErrors['dates_confirmed']) ? '' : 'hidden' ?>><?= e($termErrors['dates_confirmed'] ?? '') ?></span></div>
            </div>
        </div>
        <footer class="term-dialog__actions"><a class="button button--secondary" href="<?= e($termReturnUrl) ?>" data-close-term>ยกเลิก</a><button class="button button--primary" type="submit">บันทึกภาคการศึกษา</button></footer>
    </form>
    <script type="application/json" data-term-presets><?= json_encode(nu_academic_presets(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="application/json" data-existing-terms><?= json_encode(array_map(static fn(array $t): array => ['year'=>$t['academic_year'], 'semester'=>$t['semester']], $terms), JSON_HEX_TAG) ?></script>
</dialog>
