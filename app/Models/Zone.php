<?php

namespace App\Models;

/**
 * Model Zone đại diện cho Khối Tòa nhà (Block/Zone).
 * Kế thừa trực tiếp từ Block nhằm hợp nhất danh mục và bảo toàn 100% tương thích ngược
 * với toàn bộ code, route và consumers hiện có.
 */
class Zone extends Block
{
    // Kế thừa toàn bộ cấu hình, mối quan hệ và scopes từ Block
}
