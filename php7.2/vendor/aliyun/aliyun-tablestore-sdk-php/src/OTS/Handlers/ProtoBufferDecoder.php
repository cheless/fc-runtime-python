<?php
namespace Aliyun\OTS\Handlers;

use Aliyun\OTS\Consts\OperationTypeConst;
use Aliyun\OTS\Consts\PrimaryKeyOptionConst;
use Aliyun\OTS\Consts\PrimaryKeyTypeConst;
use Aliyun\OTS\Consts\StreamStatusConst;
use Aliyun\OTS\OTSClientException;
use Aliyun\OTS\PlainBuffer\PlainBufferCodedInputStream;
use Aliyun\OTS\PlainBuffer\PlainBufferInputStream;
use Aliyun\OTS\ProtoBuffer\Protocol\ActionType;
use Aliyun\OTS\ProtoBuffer\Protocol\BatchGetRowResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\BatchWriteRowResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\ComputeSplitPointsBySizeResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\DeleteRowResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\DescribeStreamResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\DescribeTableResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\GetRangeResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\GetRowResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\GetShardIteratorResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\GetStreamRecordResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\ListStreamResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\ListTableResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\PrimaryKeyOption;
use Aliyun\OTS\ProtoBuffer\Protocol\PrimaryKeyType;
use Aliyun\OTS\ProtoBuffer\Protocol\PutRowResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\RowInBatchWriteRowResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\StreamDetails;
use Aliyun\OTS\ProtoBuffer\Protocol\StreamStatus;
use Aliyun\OTS\ProtoBuffer\Protocol\UpdateRowResponse;
use Aliyun\OTS\ProtoBuffer\Protocol\UpdateTableResponse;

//use CreateTableResponse;
//use DeleteTableResponse;


class ProtoBufferDecoder
{
    public function handleBefore($context)
    {
        // empty
    }

    public function decodeListTableResponse($body)
    {
        $pbMessage = new ListTableResponse();
        $pbMessage->mergeFromString($body);
        $response = array();
        $tableNames = $pbMessage->getTableNames();
        for ($i = 0; $i < count($tableNames); $i++)
        {
            array_push($response, $tableNames[$i]);
        }
        return $response;
    }

    public function decodeCreateTableResponse($body)
    {
        return array();
    }

    public function decodeDeleteTableResponse($body)
    {
        return array();
    }

    private function parseCapacityUnit($pbMessage)
    {
        return array(
            'capacity_unit' => array(
                'read' => $pbMessage->getRead(),
                'write' => $pbMessage->getWrite()
            ),
        );
    }

    private function parseReservedThroughputDetails($pbMessage)
    {
        $capacityUnit = $this->parseCapacityUnit($pbMessage->getCapacityUnit());

        return array(
            'capacity_unit' => $capacityUnit['capacity_unit'],
            'last_increase_time' => $pbMessage->getLastIncreaseTime(),
            'last_decrease_time' => $pbMessage->getLastDecreaseTime()
        );
    }

    private function parseTableOptions($pbMessage)
    {
        return array(
            'time_to_live' => $pbMessage->getTimeToLive(),
            'max_versions' => $pbMessage->getMaxVersions(),
            'deviation_cell_version_in_sec' => $pbMessage->getDeviationCellVersionInSec()
        );
    }

    private function parseStreamDetails(StreamDetails $pbMessage)
    {
        if($pbMessage->getEnableStream()) {
            return array(
                'enable_stream' => true,
                'stream_id' => $pbMessage->getStreamId(),
                'expiration_time' => $pbMessage->getExpirationTime(),
                'last_enable_time' => $pbMessage->getLastEnableTime()
            );
        }else {
            return array(
                'enable_stream' => false
            );
        }
    }

    private function parserPrimaryKeySchema($primaryKeys)
    {
        $pkSchema = array();
        for ($i = 0; $i < count($primaryKeys); $i++) {
            $pkColumn = $primaryKeys[$i];
            $column = array();
            $type = null;
            $pkSchema[$i] = array();
            $pkSchema[$i][] = $pkColumn->getName();
            switch ($pkColumn->getType())
            {
                case PrimaryKeyType::INTEGER:
                    $type = PrimaryKeyTypeConst::CONST_INTEGER;
                    break;
                case PrimaryKeyType::STRING:
                    $type = PrimaryKeyTypeConst::CONST_STRING;
                    break;
                case PrimaryKeyType::BINARY:
                    $type = PrimaryKeyTypeConst::CONST_BINARY;
                    break;
                default:
                    throw new OTSClientException('Invalid column type in response.');
            }
            $pkSchema[$i][] = $type;
            if($pkColumn->hasOption()) {
                $column['type'] = $type;
                switch($pkColumn->getOption()) {
                    case PrimaryKeyOption::AUTO_INCREMENT:
                        $column['option'] = PrimaryKeyOptionConst::CONST_PK_AUTO_INCR;
                        break;
                    default:
                        throw new OTSClientException('Invalid column option in response.');
                }
                $pkSchema[$i][] = $column['option'];
            }
        }
        return $pkSchema;
    }

    public function decodeDescribeTableResponse($body)
    {
        $pbMessage = new DescribeTableResponse();
        $pbMessage->mergeFromString($body);
        $tableMeta = $pbMessage->getTableMeta();

        $response = array(
            'table_meta' => array(
                'table_name' => $tableMeta->getTableName(),
                'primary_key_schema' => $this->parserPrimaryKeySchema($tableMeta->getPrimaryKey()),
            ),
            'capacity_unit_details' => $this->parseReservedThroughputDetails($pbMessage->getReservedThroughputDetails()),
            'table_options' => $this->parseTableOptions($pbMessage->getTableOptions()),
            'stream_details' => array(
                'enable_stream' => false
            )
        );
        if($pbMessage->hasStreamDetails()) {
            $response['stream_details'] = $this->parseStreamDetails($pbMessage->getStreamDetails());
        }
        return $response;
    }

    public function decodeUpdateTableResponse($body)
    {
        $pbMessage = new UpdateTableResponse();
        $pbMessage->mergeFromString($body);
        $response = array(
            'capacity_unit_details' => $this->parseReservedThroughputDetails($pbMessage->getReservedThroughputDetails()),
            'table_options' => $this->parseTableOptions($pbMessage->getTableOptions()),
            'stream_details' => array(
                'enable_stream' => false
            )
        );
        if($pbMessage->hasStreamDetails()) {
            $response['stream_details'] = $this->parseStreamDetails($pbMessage->getStreamDetails());
        }
        return $response;
    }

    public function decodeComputeSplitPointsBySizeResponse($body)
    {
        $pbMessage = new ComputeSplitPointsBySizeResponse();
        $pbMessage->mergeFromString($body);

        $pks = $this->parserPrimaryKeySchema($pbMessage->getSchema());

        $infStart = array();
        $infEnd = array();
        foreach ($pks as $pk) {
            $infStart[] = array($pk[0], null, PrimaryKeyTypeConst::CONST_INF_MIN);
            $infEnd[] = array($pk[0], null, PrimaryKeyTypeConst::CONST_INF_MAX);
        }

        $splits = array();
        $splitPoints = $pbMessage->getSplitPoints();

        $lastPk = $infStart;
        $nowPk = $infEnd;

        foreach($splitPoints as $split) {
            $pk = $this->parseRow($split);
            $nowPk = $pk['primary_key'];
            for($i = count($nowPk); $i < count($pks); $i++) {
                $nowPk[] = array($pks[$i][0], null, PrimaryKeyTypeConst::CONST_INF_MIN);
            }
            $splits[] = array(
                'lower_bound' => $lastPk,
                'upper_bound' => $nowPk
            );
            $lastPk = $nowPk;
        }

        $splits[] = array(
            'lower_bound' => $lastPk,
            'upper_bound' => $infEnd
        );

        $locations = $pbMessage->getLocations();
        $index = 0;
        foreach($locations as $location) {
            for($i = 0; $i < $location->getRepeat(); $i++) {
                $splits[$index]['location'] = $location->getLocation();
                $index++;
            }
        }

        $response = array(
            'consumed' => $this->parseConsumed($pbMessage->getConsumed()),
            'primary_key_schema' => $pks,
            'splits' => $splits
        );
        return $response;
    }

    private function parseRow($row)
    {
        if(strlen($row) != 0) {
            $inputStream = new PlainBufferInputStream($row);
            $codedInputStream = new PlainBufferCodedInputStream($inputStream);
            return $codedInputStream->readRow();
        } else {
            return array(
                'primary_key' => array(),
                'attribute_columns' => array()
            );
        }
    }

    private function parseConsumed($pbMessage)
    {
        return $this->parseCapacityUnit($pbMessage->getCapacityUnit());
    }

    public function decodeGetRowResponse($body)
    {
        $pbMessage = new GetRowResponse();
        $pbMessage->mergeFromString($body);
        $rawRow = $this->parseRow($pbMessage->getRow());
        $response = array(
            'consumed' => $this->parseConsumed($pbMessage->getConsumed()),
            'primary_key' => $rawRow['primary_key'],
            'attribute_columns' => $rawRow['attribute_columns'],
            'next_token' => $pbMessage->getNextToken()
        );

        return $response;
    }

    public function decodePutRowResponse($body)
    {
        $pbMessage = new PutRowResponse();
        $pbMessage->mergeFromString($body);
        $rawRow = $this->parseRow($pbMessage->getRow());
        $response = array(
            'consumed' => $this->parseConsumed($pbMessage->getConsumed()),
            'primary_key' => $rawRow['primary_key'],
            'attribute_columns' => $rawRow['attribute_columns']
        );
        return $response;
    }

    public function decodeUpdateRowResponse($body)
    {
        $pbMessage = new UpdateRowResponse();
        $pbMessage->mergeFromString($body);
        $rawRow = $this->parseRow($pbMessage->getRow());
        $response = array(
            'consumed' => $this->parseConsumed($pbMessage->getConsumed()),
            'primary_key' => $rawRow['primary_key'],
            'attribute_columns' => $rawRow['attribute_columns']
        );
        return $response;
    }

    public function decodeDeleteRowResponse($body)
    {
        $pbMessage = new DeleteRowResponse();
        $pbMessage->mergeFromString($body);
        $rawRow = $this->parseRow($pbMessage->getRow());
        $response = array(
            'consumed' => $this->parseConsumed($pbMessage->getConsumed()),
            'primary_key' => $rawRow['primary_key'],
            'attribute_columns' => $rawRow['attribute_columns']
        );
        return $response;
    }

    private function parseIsOK($isOK)
    {
        // PB library will treat bool as int
        // we need to convert it back to bool
        if ($isOK) {
            return true;
        } else {
            return false;
        }
    }

    public function decodeBatchGetRowResponse($body)
    {
        $pbMessage = new BatchGetRowResponse();
        $pbMessage->mergeFromString($body);

        $tables = array();
        $inTable = $pbMessage->getTables();
        for ($i = 0; $i < count($inTable); $i++) {
            $tableInBatchGetRow = $inTable[$i];
            $rowList = array();
            $inRows = $tableInBatchGetRow->getRows();
            for ($j = 0; $j < count($inRows);$j++) {
                $rowInBatchGetRow = $inRows[$j];
                $consumed = $rowInBatchGetRow->getConsumed();
                $error = $rowInBatchGetRow->getError();
                $isOK = $this->parseIsOK($rowInBatchGetRow->getIsOk());

                if($isOK)
                {
                    $rawRow = $this->parseRow($rowInBatchGetRow->getRow());
                    $rowData = array(
                        'is_ok' => $isOK,
                        'consumed' => $this->parseConsumed($consumed),
                        'primary_key' => $rawRow['primary_key'],
                        'attribute_columns' => $rawRow['attribute_columns'],
                        'next_token' => $rowInBatchGetRow->getNextToken()
                    );
                }
                else
                {
                    $rowData = array(
                        'is_ok' => $isOK,
                        'error' => array(
                            'code' => $error->getCode(),
                            'message' =>$error->getMessage()
                        ),
                    );
                }
                array_push($rowList, $rowData);
            }

            array_push($tables, array(
                'table_name' => $tableInBatchGetRow->getTableName(),
                'rows' => $rowList,
            ));
        }

        return array('tables' => $tables);
    }


    private function decodeWriteRowItem(RowInBatchWriteRowResponse $rowItem)
    {
        $consumed = $rowItem->getConsumed();
        $error = $rowItem->getError();
        $isOK = $this->parseIsOK($rowItem->getIsOk());

        if ($isOK) {
            $rawRow = $this->parseRow($rowItem->getRow());
            $row = array(
                'is_ok' => $isOK,
                'consumed' => $this->parseConsumed($consumed),
                'primary_key' => $rawRow['primary_key'],
                'attribute_columns' => $rawRow['attribute_columns']
            );
        } else {
            $row = array(
                'is_ok' => $isOK,
                'error' => array(
                    'code' => $error->getCode(),
                    'message' => $error->getMessage()
                ),
            );
        }
        return $row;
    }

    public function decodeBatchWriteRowResponse($body)
    {
        $pbMessage = new BatchWriteRowResponse();
        $pbMessage->mergeFromString($body);
        $ret = array();
        $ret['tables'] = array();
        $tables = $pbMessage->getTables();
        for($i = 0; $i < count($tables); $i++) {
            $table = array();
            $table['rows'] = array();
            $tableItem = $tables[$i];
            $tableName = $tableItem->getTableName();
            $table['table_name'] = $tableName;
            $rows = $tableItem->getRows();
            for($j = 0; $j < count($rows); $j++) {
                $rowItem = $rows[$j];
                $row = self::decodeWriteRowItem($rowItem);
                $table['rows'][] = $row;
            }
            $ret['tables'][] = $table;
        }
        return $ret;
    }

    public function decodeGetRangeResponse($body)
    {
        $pbMessage = new GetRangeResponse();
        $pbMessage->mergeFromString($body);
        $consumed = $pbMessage->getConsumed();

        $rowList = array();
        $row = $pbMessage->getRows();
        if(strlen($row) != 0) {
            $inputStream = new PlainBufferInputStream($row);
            $codedInputStream = new PlainBufferCodedInputStream($inputStream);
            $rowList = $codedInputStream->readRows();
        }

        $nextStartPrimaryKey = null;
        $nextPK = $pbMessage->getNextStartPrimaryKey();
        if(strlen($nextPK) != 0) {
            $inputStream = new PlainBufferInputStream($nextPK);
            $codedInputStream = new PlainBufferCodedInputStream($inputStream);
            $row = $codedInputStream->readRow();
            $nextStartPrimaryKey = $row['primary_key'];
        }

        return array(
            'consumed' => $this->parseConsumed($consumed),
            'next_start_primary_key' => $nextStartPrimaryKey,
            'rows' => $rowList,
            'next_token' => $pbMessage->getNextToken()
        );
    }

    private function parseStream($message) {
        return array(
            'stream_id' => $message->getStreamId(),
            'table_name' => $message->getTableName(),
            'creation_time' => $message->getCreationTime()
        );
    }

    public function decodeListStreamResponse($body)
    {
        $pbMessage = new ListStreamResponse();
        $pbMessage->mergeFromString($body);
        $streams = $pbMessage->getStreams();
        $outStreams = array();
        foreach($streams as $stream) {
            $outStreams[] = $this->parseStream($stream);
        }
        $response = array(
            'streams' => $outStreams
        );
        return $response;
    }

    private function parseStreamShard($message) {
        return array(
            'shard_id' => $message->getShardId(),
            'parent_id' => $message->getParentId(),
            'parent_sibling_id' => $message->getParentSiblingId()
        );
    }

    private function parseStreamStatus($message) {
        if($message == StreamStatus::STREAM_ENABLING){
            return StreamStatusConst::CONST_ENABLING;
        }
        else if($message == StreamStatus::STREAM_ACTIVE) {
            return StreamStatusConst::CONST_ACTIVE;
        }
        else {
            throw new OTSClientException('unknown stream status.');
        }
    }

    public function decodeDescribeStreamResponse($body)
    {
        $pbMessage = new DescribeStreamResponse();
        $pbMessage->mergeFromString($body);

        $oriShards = $pbMessage->getShards();
        $shards = array();
        foreach ($oriShards as $shard) {
            $shards[] = $this->parseStreamShard($shard);
        }

        $response = array(
            'stream_id' => $pbMessage->getStreamId(),
            'expiration_time' => $pbMessage->getExpirationTime(),
            'table_name' => $pbMessage->getTableName(),
            'creation_time' => $pbMessage->getCreationTime(),
            'stream_status' => $this->parseStreamStatus($pbMessage->getStreamStatus()),
            'shards' => $shards,
            'next_shard_id' => $pbMessage->getNextShardId()
        );
        return $response;
    }

    public function decodeGetShardIteratorResponse($body)
    {
        $pbMessage = new GetShardIteratorResponse();
        $pbMessage->mergeFromString($body);
        $response = array(
            'shard_iterator' => $pbMessage->getShardIterator()
        );
        return $response;
    }

    private function parserActionType($message) {
        if($message == ActionType::PUT_ROW){
            return OperationTypeConst::CONST_PUT;
        }
        else if($message == ActionType::UPDATE_ROW) {
            return OperationTypeConst::CONST_UPDATE;
        }
        else if($message == ActionType::DELETE_ROW) {
            return OperationTypeConst::CONST_DELETE;
        }
        else {
            throw new OTSClientException('unknown action type.');
        }
    }

    private function parseStreamRecord($message) {
        $type = $message->getActionType();
        $rawRow = $this->parseRow($message->getRecord());
        $op = $this->parserActionType($type);

        if($op == OperationTypeConst::CONST_UPDATE ) {
            $put = array();
            $delete = array();
            $deleteAll = array();
            foreach($rawRow['attribute_columns'] as $column) {
                if(!is_null($column[1])) {
                    $put[] = $column;
                } else if(!is_null($column[3])) {
                    $delete[] = array($column[0],$column[3]);
                } else {
                    $deleteAll[] = $column[0];
                }
            }
            return array(
                'operation_type' => $op,
                'primary_key' => $rawRow['primary_key'],
                'update_of_attribute_columns' => array(
                    'PUT' => $put,
                    'DELETE' => $delete,
                    'DELETE_ALL'=> $deleteAll
                ),
                'extension' => $rawRow['extension']
            );

        } else {
            return array(
                'operation_type' => $op,
                'primary_key' => $rawRow['primary_key'],
                'attribute_columns' => $rawRow['attribute_columns'],
                'extension' => $rawRow['extension']
            );
        }
    }

    public function decodeGetStreamRecordResponse($body)
    {
        $pbMessage = new GetStreamRecordResponse();
        $pbMessage->mergeFromString($body);
        $oriRecords = $pbMessage->getStreamRecords();
        $records = array();
        foreach ($oriRecords as $record) {
            $records[] = $this->parseStreamRecord($record);
        }
        $response = array(
            'next_shard_iterator' => $pbMessage->getNextShardIterator(),
            'stream_records' => $records
        );
        return $response;
    }


    public function handleAfter($context)
    {
        if ($context->otsServerException != null) {
            return;
        }

        $apiName = $context->apiName;
        $methodName = 'decode' . $apiName . 'Response';
        $response = $this->$methodName($context->responseBody);
        $context->response = $response;

        $debugLogger = $context->clientConfig->debugLogHandler;
        if ($debugLogger != null) {
            $debugLogger("$apiName Response " . json_encode($response));
        }
    }
}

