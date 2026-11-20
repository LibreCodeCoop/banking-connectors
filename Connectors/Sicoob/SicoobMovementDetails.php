<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

/**
 * Sicoob-specific statement fields. The generic UI does not know bank keys.
 * Only allowlisted fields survive; no raw response or secret is retained.
 */
final class SicoobMovementDetails
{
    /** @return array<string,array{label:string,group:string}> */
    public static function definitions(): array
    {
        $definitions=[
            'pix_end_to_end_id'=>['label'=>'bank_detail_pix_end_to_end_id','group'=>'pix'],
            'pix_txid'=>['label'=>'bank_detail_pix_txid','group'=>'pix'],
            'payer_message'=>['label'=>'bank_detail_payer_message','group'=>'pix'],
            'sender_name'=>['label'=>'bank_detail_sender_name','group'=>'counterparty'],
            'sender_document_masked'=>['label'=>'bank_detail_sender_document_masked','group'=>'counterparty'],
            'recipient_name'=>['label'=>'bank_detail_recipient_name','group'=>'counterparty'],
            'origin_institution'=>['label'=>'bank_detail_origin_institution','group'=>'institution'],
            'destination_institution'=>['label'=>'bank_detail_destination_institution','group'=>'institution'],
            'origin_ispb'=>['label'=>'bank_detail_origin_ispb','group'=>'institution'],
            'destination_ispb'=>['label'=>'bank_detail_destination_ispb','group'=>'institution'],
            'document_number'=>['label'=>'bank_detail_document_number','group'=>'bank'],
            'batch_date'=>['label'=>'bank_detail_batch_date','group'=>'bank'],
            'complement'=>['label'=>'bank_detail_complement','group'=>'bank'],
            'identification'=>['label'=>'bank_detail_identification','group'=>'bank'],
            'history'=>['label'=>'bank_detail_history','group'=>'bank'],
            'transaction_type'=>['label'=>'bank_detail_transaction_type','group'=>'bank'],
            'history_code'=>['label'=>'bank_detail_history_code','group'=>'bank'],
            'time'=>['label'=>'bank_detail_time','group'=>'bank'],
        ];
        foreach ($definitions as &$definition) {
            $definition['label']='banking-connectors::general.'.$definition['label'];
            $definition['group']='banking-connectors::general.bank_details_group_'.$definition['group'];
        }
        unset($definition);
        return $definitions;
    }

    /** @return array<string,string> */
    public static function fromStatement(array $row): array
    {
        $allowlist=[
            'endToEndId'=>'pix_end_to_end_id','endToEndID'=>'pix_end_to_end_id',
            'e2eId'=>'pix_end_to_end_id','txid'=>'pix_txid',
            'numeroDocumento'=>'document_number',
            'descInfComplementar'=>'complement','dataLote'=>'batch_date',
            'complemento'=>'complement','informacoesComplementares'=>'complement',
            'identificacao'=>'identification','historico'=>'history',
            'tipoTransacao'=>'transaction_type','codigoHistorico'=>'history_code',
            'nomeRemetente'=>'sender_name','nomePagador'=>'sender_name',
            'nomeFavorecido'=>'recipient_name','nomeRecebedor'=>'recipient_name',
            'instituicaoOrigem'=>'origin_institution','instituicaoDestino'=>'destination_institution',
            'ispbOrigem'=>'origin_ispb','ispbDestino'=>'destination_ispb',
            'hora'=>'time','horario'=>'time',
        ];
        $result=[];
        foreach ($allowlist as $source=>$target) {
            $value=$row[$source]??null;
            if (!is_string($value) && !is_int($value)) continue;
            $value=trim((string)$value);
            if ($value==='' || strlen($value)>240 || !preg_match('//u',$value) ||
                preg_match('/[\x00-\x1F\x7F]/',$value)) continue;
            $result[$target]??=$value;
        }
        foreach (['cpfCnpjRemetente','cpfCnpjPagador','documentoRemetente'] as $key) {
            $input=$row[$key]??null;
            if (!is_string($input)) continue;
            $digits=preg_replace('/[^0-9]/','',$input);
            if (strlen($digits)===11) {
                $result['sender_document_masked']=substr($digits,0,3).'.***.***-'.substr($digits,-2);
                break;
            }
            if (strlen($digits)===14) {
                $result['sender_document_masked']=substr($digits,0,2).'.***.***/****-'.substr($digits,-2);
                break;
            }
        }
        return $result;
    }

    /** Safe Pix consultation data, independently verified by the caller. */
    public static function fromPix(array $pix): array
    {
        $result=self::fromStatement($pix);
        if (is_array($pix['pagador']??null)) {
            $payer=$pix['pagador'];
            $result+=self::fromStatement([
                'nomeRemetente'=>$payer['nome']??null,
                'cpfCnpjPagador'=>$payer['cpf']??($payer['cnpj']??null),
            ]);
        }
        $message=$pix['infoPagador']??null;
        if (is_string($message) && strlen($message)<=240 && preg_match('//u',$message) &&
            !preg_match('/[\x00-\x1F\x7F]/',$message)) {
            $result['payer_message']=trim($message);
        }
        if (is_string($pix['horario']??null) && strlen($pix['horario'])<=40) {
            $result['time']=$pix['horario'];
        }
        return $result;
    }
}
