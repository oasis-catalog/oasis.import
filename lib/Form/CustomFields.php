<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;

class CustomFields
{
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
