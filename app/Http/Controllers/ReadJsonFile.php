<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Constraint\Operator;
use function PHPUnit\Framework\returnSelf;

class ReadJsonFile extends Controller
{
    public function index()
    {
        $content = File::get(base_path('public/jsonFile.json'));
        $json = json_decode($content);

        $allSheetResults = [];

        if (is_object($json) && isset($json->sheets)) {
            $sheets = $json->sheets;

            foreach ($sheets as $sheet) {
                $sheetData = [];
                $rows = $sheet->data;
                $keyValueArray = $this->mapSheetDataToArray($rows);

                foreach ($keyValueArray as $key => $value) {
                    if (is_string($value) && strpos($value, '=') === 0) {
                        $evaluatedValue = $this->evaluateCellValue($value, $keyValueArray);
                        $keyValueArray[$key] = $evaluatedValue;
                    }
                }

                foreach ($rows as $row) {
                    $rowData = [];
                    foreach ($row as $cell) {
                        if (is_string($cell) && strpos($cell, '=') === 0) {
                            $evaluatedValue = $this->evaluateCellValue($cell, $keyValueArray);
                            $rowData[] = $evaluatedValue;
                        } else {
                            $rowData[] = $cell;
                        }
                    }

                    $sheetData[] = $rowData;
                }

                $allSheetResults[] = [
                    'id' => $sheet->id,
                    'data' => $sheetData,
                ];
            }
        }

        $results = [
            'email' => 'brigita.grybaite@gmail.com',
            'results' => $allSheetResults,
        ];

        return $results;
    }

    private function mapSheetDataToArray($rows)
    {
        $keyValueArray = [];

        foreach ($rows as $rowIndex => $row) {
            $columnLetters = range('A', 'Z');
            $rowNumber = $rowIndex + 1;

            if ($rowIndex === 0 && $row[0] === 'First') {
                foreach ($row as $cellIndex => $cell) {
                    $column = $columnLetters[$cellIndex];
                    $row = $column . $rowNumber;
                    $evaluatedValue = $this->evaluateCellValue($cell, $keyValueArray);
                    $keyValueArray[$row] = $evaluatedValue;
                }
            } elseif ($rowIndex === count($rows) - 1 && $row[count($row) - 1] === 'Last') {
                $lastIndex = count($row) - 1;

                for ($i = $lastIndex - 1; $i >= 0; $i--) {
                    $row[$i] = $row[$i + 1];
                }

                foreach ($row as $cellIndex => $cell) {
                    $column = $columnLetters[$cellIndex];
                    $rowKey = $column . $rowNumber;
                    $keyValueArray[$rowKey] = $cell;
                }
            } else {
                foreach ($row as $cellIndex => $cell) {
                    $column = $columnLetters[$cellIndex];
                    $row = $column . $rowNumber;
                    $keyValueArray[$row] = $cell;
                }
            }
        }

        return $keyValueArray;
    }

    private function evaluateCellValue($value, $keyValueArray)
    {
        if (strpos($value, '=') === 0) {
            $expression = substr($value, 1);
            $partsOfExpression = preg_split('/[\s,()]+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
            $operator = $partsOfExpression[0];
            $values = array_slice($partsOfExpression, 1);

            switch ($operator) {
                case 'SUM':
                    $sum = 0;
                    foreach ($values as $value) {
                        if (is_numeric($value)) {
                            $sum += $value;
                        } elseif (isset($keyValueArray[$value]) && is_numeric($keyValueArray[$value])) {
                            $sum += $keyValueArray[$value];
                        } else {
                            return '#ERROR: Invalid or non-numeric value';
                        }
                    }
                    return $sum;
                    break;

                case 'MULTIPLY':
                    $multiply = 1;
                    foreach ($values as $value) {
                        if (is_numeric($value)) {
                            $multiply *= $value;
                        } elseif (isset($keyValueArray[$value]) && is_numeric($keyValueArray[$value])) {
                            $multiply *= $keyValueArray[$value];
                        } else {
                            return '#ERROR: Invalid or non-numeric value';
                        }
                    }
                    return $multiply;
                    break;

                case 'DIVIDE':
                    $validValues = $this->areValuesValid($values, $keyValueArray);
                    if ($validValues) {
                        [$newValue1, $newValue2] = $validValues;
                        if (is_numeric($newValue1) && is_numeric($newValue2)) {
                            if (abs($newValue2) > 1e-7) {
                                return $newValue1 / $newValue2;
                            } else {
                                return '#ERROR: Division by zero it\'s not allowed';
                            }
                        } else {
                            return '#ERROR: Invalid or non-numeric value';
                        }
                    } else {
                        return '#ERROR: Invalid value';
                    }
                    break;

                case 'GT':
                    $validValues = $this->areValuesValid($values, $keyValueArray);
                    if ($validValues) {
                        [$newValue1, $newValue2] = $validValues;
                        return $newValue1 > $newValue2 ? true : false;
                    }
                    break;

                case 'EQ':
                    $validValues = $this->areValuesValid($values, $keyValueArray);
                    if ($validValues) {
                        [$newValue1, $newValue2] = $validValues;
                        return $newValue1 === $newValue2 ? true : false;
                    }
                    break;

                case 'NOT':
                    if (count($values) === 1) {
                        $value = $values[0];
                        if (isset($keyValueArray[$value]) && is_bool($keyValueArray[$value])) {
                            $newValue = $keyValueArray[$value];
                            return !$newValue;
                        }
                    }
                    break;

                case 'AND':
                    $result = array_reduce($values, function ($previous, $current) use ($keyValueArray) {
                        $value = $this->evaluateCellValue('=' . $current, $keyValueArray);
                        if (!is_bool($value) && is_numeric($value)) {
                            return '#ERROR: Incompatible types';
                        }
                        return $previous && $value;
                    }, true);
                    return $result;
                    break;

                case 'OR':
                    $result = array_reduce($values, function ($previous, $current) use ($keyValueArray) {
                        $value = $this->evaluateCellValue('=' . $current, $keyValueArray);
                        if (!is_bool($value) && is_numeric($value)) {
                            return '#ERROR: Invalid value';
                        } else {
                            return $previous || (bool)$value;
                        }
                    }, false);
                    return $result;
                    break;

                case "IF":
                    $pattern = '/(IF\(|[A-Z]+\([^)]+\)|[A-Z0-9]+)/';
                    preg_match_all($pattern, $expression, $matches);
                    $newExpressionArray = $matches[0];
                    array_shift($newExpressionArray);

                    $condition = $this->evaluateCellValue('=' . $newExpressionArray[0], $keyValueArray);
                    $valueIfTrue = $this->evaluateCellValue('=' . $newExpressionArray[1], $keyValueArray);
                    $valueIfFalse = $this->evaluateCellValue('=' . $newExpressionArray[2], $keyValueArray);

                    if (is_bool($condition)) {
                        return $condition ? $valueIfTrue : $valueIfFalse;
                    } else {
                        return '#ERROR: Invalid condition';
                    }
                    break;

                case 'CONCAT':
                    $pattern = '/[A-Z]+\d+|\"(.*?)\"/';
                    preg_match_all($pattern, $expression, $matches);
                    $newExpressionArray = $matches[0];
                    $concatenatedString = '';

                    foreach ($newExpressionArray as $value) {
                        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                            $concatenatedString .= substr($value, 1, -1);
                        } else {
                            $value = $this->evaluateCellValue('=' . $value, $keyValueArray);
                            $concatenatedString .= $value;
                        }
                    }
                    return $concatenatedString;
                    break;

                default:
                    if (isset($keyValueArray[$operator])) {
                        return $keyValueArray[$operator];
                    } else {
                        return '#ERROR: Invalid reference';
                    }
            }
        }

        return $value;
    }

    private function areValuesValid($values, $keyValueArray)
    {
        if (count($values) === 2) {
            $value1 = $values[0];
            $value2 = $values[1];

            if (isset($keyValueArray[$value1]) && isset($keyValueArray[$value2])) {
                $newValue1 = $keyValueArray[$value1];
                $newValue2 = $keyValueArray[$value2];

                return [$newValue1, $newValue2];
            }
        }

        return false;
    }
}
