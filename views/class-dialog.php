<?php
$panelRequestedId = max(0,(int)($_GET['class_id'] ?? 0));
$panelClass = $panelRequestedId ? get_class_session($panelRequestedId) : null;
$panelReturnQuery = ['page'=>$page] + class_panel_context();
$panelCloseUrl='?'.http_build_query($panelReturnQuery);
?>
<dialog id="class-info-dialog" class="term-dialog class-info-dialog" aria-labelledby="class-panel-title" <?= $panelRequestedId?'open':'' ?> data-initial-class="<?= $panelRequestedId ?>">
    <header class="term-dialog__header"><h2 id="class-panel-title">รายละเอียดคลาส</h2><a class="icon-button" href="<?= e($panelCloseUrl) ?>" data-close-class aria-label="ปิดรายละเอียดคลาส"><span data-icon="x"></span></a></header>
    <div class="term-dialog__body" data-class-body>
        <?php if ($panelRequestedId && $flash): ?><p class="alert alert--<?= e($flash['type']) ?>" role="<?= $flash['type']==='error'?'alert':'status' ?>"><strong><?= e($flash['title']) ?></strong> <?= e($flash['message'] ?? '') ?></p><?php endif; ?>
        <?php if ($panelClass): require __DIR__.'/class-panel.php'; elseif ($panelRequestedId): ?><p class="alert alert--error" role="alert">ไม่พบคลาสหรือไม่มีสิทธิ์เข้าถึง</p><?php endif; ?>
    </div>
    <footer class="term-dialog__actions"><a class="button button--secondary" href="<?= e($panelCloseUrl) ?>" data-close-class>ปิดหน้าต่าง</a></footer>
</dialog>
