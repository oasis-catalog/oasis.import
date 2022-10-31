<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;

class CustomFields
{

    /**
     * Get html tree categories
     *
     * @param $module_id
     * @param $option
     */
    public function treeCategories($module_id, $option)
    {
        if ($option === null) {
            return;
        }

        $siteValues = Option::get($module_id, $option[0], $option[2]);

        if (empty($siteValues) || $siteValues === 'Y') {
            $siteValues = [];
        } else {
            $siteValues = explode(',', $siteValues);
        }

        ?>
        <tr>
            <td width="50%">
                <?
                echo "<label for='" . htmlspecialcharsbx($option[0]) . "'>" . $option[1] . "</label>";
                ?>
            </td>
            <td width="50%">
                <?
                echo '<ul id="tree">' . PHP_EOL . self::buildTreeCats($option[3][1], $siteValues) . PHP_EOL . '</ul>' . PHP_EOL;
                ?>
            </td>
        </tr>
        <?
    }

    /**
     * Build tree categories
     *
     * @param $categories
     * @param array $checkedArr
     * @param string $treeCats
     * @param int $parent_id
     * @param bool $sw
     * @return string
     */
    private function buildTreeCats($categories, array $checkedArr = [], string $treeCats = '', int $parent_id = 0, bool $sw = false): string
    {
        if (!empty($categories[$parent_id])) {
            $treeCats .= $sw ? '<ul>' . PHP_EOL : '';

            for ($i = 0; $i < count($categories[$parent_id]); $i++) {
                if (empty($checkedArr)) {
                    $checked = $categories[$parent_id][$i]['level'] == 1 ? ' checked' : '';
                } else {
                    $checked = array_search($categories[$parent_id][$i]['id'], $checkedArr) !== false ? ' checked' : '';
                }

                $treeCats .= '<li><label><input id="category-' . $categories[$parent_id][$i]['id'] . '" type="checkbox" name="categories[' . $categories[$parent_id][$i]['id'] . ']" value="Y"' . $checked . '/> ' . $categories[$parent_id][$i]['name'] . '</label>' . PHP_EOL;
                $treeCats = self::buildTreeCats($categories, $checkedArr, $treeCats, $categories[$parent_id][$i]['id'], true) . '</li>' . PHP_EOL;
            }
            $treeCats .= $sw ? '</ul>' . PHP_EOL : '';
        }

        return $treeCats;
    }

    /**
     * Group checkboxes
     *
     * @param $module_id
     * @param $option
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public function checkboxes($module_id, $option)
    {
        if ($option === null) {
            return;
        }

        $values = $option[3][1];
        $siteValues = explode(',', Option::get($module_id, $option[0], $option[2]));
        ?>
        <tr>
            <td width="50%">
                <?
                echo "<label for='" . htmlspecialcharsbx($option[0]) . "'>" . $option[1] . "</label>";
                ?>
            </td>
            <td width="50%">
                <?
                foreach ($values as $key => $value) {
                    $checked = in_array($key, $siteValues) ? ' checked' : '';
                    echo '<input type="checkbox" id="' . htmlspecialcharsbx($option[0]) . '-' . $key . '" name="' . htmlspecialcharsbx($option[0]) . '[' . $key . ']" value="Y"' . $checked . '>' . PHP_EOL;
                    echo '<label for="' . htmlspecialcharsbx($option[0]) . '-' . $key . '">' . $value . '</label><br/>' . PHP_EOL;
                }
                ?>
            </td>
        </tr>
        <?
    }

    /**
     * Hidden Select
     *
     * @param $module_id
     * @param $option
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public function hiddenSelect($module_id, $option)
    {
        if ($option === null) {
            return;
        }

        $multiStocks = Option::get($module_id, 'stocks');
        $siteValue = Option::get($module_id, $option[0]);

        if ($multiStocks == 'Y') {
            $style = '';
        } else {
            $style = 'display: none;';
            $siteValue = '';
        }

        ?>
        <tr id="<?php echo $option[0]; ?>" style="<?php echo $style; ?>">
            <?
            renderLable($option, []);
            renderInput($option, [], $option[0], $siteValue ?? $option[2]);
            ?>
        </tr>
        <?
    }
}
