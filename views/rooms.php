<?php
$rooms = list_rooms();
$activeVisits = db()->query('SELECT room_id,COUNT(*) AS total FROM room_visits WHERE check_out_at IS NULL GROUP BY room_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$posterRoom = null;
foreach ($rooms as $room) {
    if ($room['id'] === (int)($_GET['qr_room'] ?? 0)) $posterRoom = $room;
}

$roomErrors = $_SESSION['room_form_errors'] ?? [];
$roomInput = $_SESSION['room_form_input'] ?? [];
unset($_SESSION['room_form_errors'], $_SESSION['room_form_input']);

$editingRoom = null;
$editRoomId = (int)($roomInput['room_id'] ?? ($_GET['edit_room'] ?? 0));
if ($user['role'] === 'admin' && $editRoomId > 0) {
    $editingRoom = get_room_by_id($editRoomId);
}
$roomDialogOpen = $user['role'] === 'admin'
    && (isset($_GET['new_room']) || $editingRoom !== null || $roomErrors !== []);

$roomValues = [
    'code' => '',
    'name' => '',
    'building' => '',
    'floor' => '',
    'status' => 'available',
    'description' => '',
];
if ($editingRoom) {
    $roomValues = array_replace($roomValues, [
        'code' => $editingRoom['code'],
        'name' => $editingRoom['name'],
        'building' => $editingRoom['building'],
        'floor' => $editingRoom['floor'] ?? '',
        'status' => $editingRoom['status'],
        'description' => $editingRoom['description'] ?? '',
    ]);
}
$roomValues = array_replace($roomValues, array_intersect_key($roomInput, $roomValues));

$roomTotal = count($rooms);
$roomSearch = trim((string)($_GET['q'] ?? ''));
if ($roomSearch !== '') {
    $rooms = array_filter(
        $rooms,
        static fn($r) => stripos($r['code'].' '.$r['name'].' '.$r['building'], $roomSearch) !== false
    );
}
$roomStatusOptions = [
    'available' => 'พร้อมใช้งาน',
    'occupied' => 'กำลังใช้งาน (กำหนดเอง)',
    'maintenance' => 'ปิดปรับปรุง',
    'inactive' => 'ปิดใช้งาน',
];
?>
<header class="page-header">
    <div>
        <h1>ห้องปฏิบัติการ</h1>
        <p>จัดการห้องและ QR ประจำห้องได้จากหน้านี้ ระบบไม่จำกัดจำนวนผู้ลงชื่อหรือผู้เข้าใช้ตามความจุห้อง</p>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
        <a class="button button--primary" href="?page=rooms&amp;new_room=1"><span data-icon="plus"></span>เพิ่มห้อง</a>
    <?php endif; ?>
</header>

<form method="get" class="filter-bar">
    <input type="hidden" name="page" value="rooms">
    <label class="field">
        <span>ค้นหาห้อง</span>
        <input name="q" value="<?= e($roomSearch) ?>" placeholder="รหัสห้อง ชื่อห้อง หรืออาคาร" maxlength="100">
    </label>
    <button class="button button--secondary" type="submit">ค้นหา</button>
    <?php if ($roomSearch !== ''): ?><a class="text-link" href="?page=rooms">ล้างคำค้น</a><?php endif; ?>
</form>

<div class="section-heading">
    <h2>รายการห้อง</h2>
    <span class="result-count">พบ <?= count($rooms) ?> จาก <?= $roomTotal ?> ห้อง</span>
</div>

<?php if (!$rooms): ?>
    <p class="inline-note" role="status">ไม่พบห้องที่ตรงกับคำค้น ลองค้นด้วยรหัสห้องหรืออาคาร</p>
<?php endif; ?>

<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>ห้องปฏิบัติการ</th>
                <th>ตำแหน่ง</th>
                <th>สถานะห้อง</th>
                <th>นอกคลาสที่ยังไม่กดออก</th>
                <th>การดำเนินการ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rooms as $room): ?>
            <tr>
                <td>
                    <strong><?= e($room['code']) ?></strong>
                    <small><?= e($room['name']) ?></small>
                </td>
                <td><?= e($room['building'].' · ชั้น '.($room['floor'] ?: '—')) ?></td>
                <td>
                    <?= e($room['status'] === 'available'
                        ? ($room['live_status'] === 'active' ? 'มีคลาสตามตาราง' : 'พร้อมรับการเข้าใช้')
                        : status_label($room['status'])) ?>
                </td>
                <td>
                    <?= (int)($activeVisits[$room['id']] ?? 0) ?> คน
                    <small>ไม่จำกัดจำนวนผู้ลงชื่อ</small>
                </td>
                <td>
                    <div class="button-group">
                        <?php if ($room['status'] !== 'inactive'): ?>
                            <a class="button button--secondary" href="?page=rooms&amp;qr_room=<?= $room['id'] ?>" aria-haspopup="dialog">QR หน้าห้อง / พิมพ์</a>
                        <?php endif; ?>
                        <?php if ($room['status'] === 'available'): ?>
                            <a class="text-link" href="?page=classes&amp;room_id=<?= $room['id'] ?>&amp;new_once=1">สร้างคลาสเรียน</a>
                        <?php endif; ?>
                        <?php if ($user['role'] === 'admin'): ?>
                            <a class="text-link" href="?page=rooms&amp;edit_room=<?= $room['id'] ?>">แก้ไข</a>
                            <form method="post"
                                  action="?page=rooms"
                                  data-confirm="ต้องการลบห้อง <?= e($room['code']) ?> หรือไม่? หากมีประวัติการใช้งาน ระบบจะปิดใช้งานแทนการลบถาวรเพื่อรักษารายงานย้อนหลัง"
                                  data-confirm-title="ยืนยันการลบห้อง"
                                  data-confirm-label="ลบห้อง"
                                  data-confirm-danger="true">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_room">
                                <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                                <button class="text-button" type="submit">ลบ</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="helper-text">จำนวนที่ยังไม่กดออกเป็นสถานะจากการลงชื่อ ไม่ใช่จำนวนคนที่ตรวจวัดในห้อง และไม่มีการปิดรับเพราะจำนวนคนครบ</p>
<?php if ($user['role'] === 'admin'): ?>
    <p><a class="text-link" href="?page=reports&amp;tab=walkins&amp;visit_status=active">ตรวจรายการนอกคลาสที่ยังไม่กดออก</a></p>
<?php endif; ?>

<?php if ($roomDialogOpen): ?>
<dialog class="term-dialog" id="room-form-dialog" aria-labelledby="room-form-title" open>
    <header class="term-dialog__header">
        <div>
            <h2 id="room-form-title"><?= $editingRoom ? 'แก้ไขห้อง '.$editingRoom['code'] : 'เพิ่มห้องปฏิบัติการ' ?></h2>
            <p>กำหนดข้อมูลห้องและสถานะการใช้งาน โดยไม่ต้องระบุความจุสูงสุด</p>
        </div>
        <a href="?page=rooms" class="icon-button" aria-label="ปิดแบบฟอร์มห้อง"><span data-icon="x"></span></a>
    </header>

    <form method="post" action="?page=rooms">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editingRoom ? 'update_room' : 'create_room' ?>">
        <?php if ($editingRoom): ?><input type="hidden" name="room_id" value="<?= $editingRoom['id'] ?>"><?php endif; ?>

        <div class="term-dialog__body">
            <?php if ($roomErrors): ?>
                <div class="alert alert--error" role="alert">
                    <strong>ยังไม่ได้บันทึกข้อมูลห้อง</strong>
                    <span><?= e(implode(' ', array_values($roomErrors))) ?></span>
                </div>
            <?php endif; ?>

            <div class="form-grid">
                <label class="field">
                    <span>รหัสห้อง</span>
                    <input name="code" value="<?= e($roomValues['code']) ?>" maxlength="30" pattern="[A-Za-z0-9._-]{1,30}" required>
                </label>
                <label class="field">
                    <span>ชื่อห้อง</span>
                    <input name="name" value="<?= e($roomValues['name']) ?>" maxlength="150" required>
                </label>
                <label class="field">
                    <span>อาคาร</span>
                    <input name="building" value="<?= e($roomValues['building']) ?>" maxlength="150" required>
                </label>
                <label class="field">
                    <span>ชั้น</span>
                    <input name="floor" value="<?= e($roomValues['floor']) ?>" maxlength="30" placeholder="เช่น 2">
                </label>
                <label class="field">
                    <span>สถานะห้อง</span>
                    <select name="status">
                        <?php foreach ($roomStatusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $roomValues['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label class="field">
                <span>หมายเหตุ (ไม่บังคับ)</span>
                <textarea name="description" rows="3" maxlength="500"><?= e($roomValues['description']) ?></textarea>
            </label>
            <p class="helper-text">จำนวนผู้ลงชื่อเข้าเรียนและการเข้าใช้นอกคลาสไม่ถูกจำกัดด้วยค่าความจุห้องอีกต่อไป</p>
        </div>

        <footer class="term-dialog__actions">
            <a href="?page=rooms" class="button button--secondary">ยกเลิก</a>
            <button class="button button--primary" type="submit"><?= $editingRoom ? 'บันทึกการแก้ไข' : 'เพิ่มห้อง' ?></button>
        </footer>
    </form>
</dialog>
<?php endif; ?>

<?php if ($posterRoom): ?>
<dialog class="term-dialog room-qr-dialog" id="room-qr-dialog" aria-labelledby="room-qr-title" open>
    <header class="term-dialog__header">
        <div>
            <h2 id="room-qr-title">QR ประจำห้อง <?= e($posterRoom['code']) ?></h2>
            <p>พิมพ์ติดหน้าห้องได้ ใช้ลิงก์เดิมทุกคลาส</p>
        </div>
        <a href="?page=rooms" class="icon-button" data-close-room-qr aria-label="ปิด QR หน้าห้อง"><span data-icon="x"></span></a>
    </header>
    <div class="term-dialog__body">
        <section class="room-qr-poster" data-room-poster>
            <p>LUMS · ลงชื่อเข้าใช้ห้องปฏิบัติการ</p>
            <h2><?= e($posterRoom['code']) ?></h2>
            <h3><?= e($posterRoom['name']) ?></h3>
            <div data-room-qr="<?= e(public_room_url($posterRoom['qr_code'])) ?>" role="img" aria-label="QR ประจำห้อง <?= e($posterRoom['code']) ?>"></div>
            <p>สแกนก่อนเข้าใช้ · ตรวจชื่อห้อง · ลงชื่อของตนเอง</p>
            <p>มีคลาส: เลือกลงชื่อเข้าเรียน<br>ไม่มีคลาส: ระบุวัตถุประสงค์ และกดออกเมื่อใช้เสร็จ</p>
        </section>
        <label class="field">
            <span>ลิงก์ประจำห้อง (ใช้แทนการสแกนได้)</span>
            <input data-room-link value="<?= e(public_room_url($posterRoom['qr_code'])) ?>" readonly>
        </label>
        <p data-room-qr-feedback role="status"></p>
        <div class="button-group">
            <button class="button button--secondary" data-copy-room-link type="button">คัดลอกลิงก์</button>
            <a class="text-link" target="_blank" rel="noopener" href="<?= e(public_room_url($posterRoom['qr_code'])) ?>">เปิดหน้าลงชื่อ</a>
        </div>
    </div>
    <footer class="term-dialog__actions">
        <button class="button button--secondary" data-download-room-qr type="button" disabled>ดาวน์โหลด QR (PNG)</button>
        <button class="button button--primary" data-print-room-qr type="button" disabled>พิมพ์ป้าย / PDF</button>
    </footer>
</dialog>
<?php endif; ?>
