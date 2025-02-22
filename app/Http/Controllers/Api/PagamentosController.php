<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\Individuo;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\AsaasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagamentosController extends Controller
{
    public function cadastroPagamento(Request $request)
    {
        DB::beginTransaction();
        $formData = $request->all();
        $user = $request->user();
        $formData['individuo']['documento'] = so_numero($formData['individuo']['documento']);
        $formData['individuo']['nome'] = $formData['individuo']['nome_razao_social'];
        $formData['individuo']['celular'] = so_numero($formData['individuo']['celular']);
        $formData['individuo']['email_contato'] = $user->email;

        if (strlen($formData['individuo']['documento']) > 11) {
            $formData['individuo']['tipo_pessoa'] = 'J';
            $formData['individuo']['razao_social'] = $formData['individuo']['nome_razao_social'];
        } else {
            $formData['individuo']['tipo_pessoa'] = 'F';
        }

        $individuo = Individuo::create($formData['individuo']);
        $individuo->individuosEndereco()->create($formData['individuo']['individuos_endereco']);

        $asaasService = new AsaasService();
        $response = $asaasService->createClient([
            "name" => $individuo->nome,
            "cpfCnpj" => $individuo->documento,
            "email" => $user->email,
            "mobilePhone" => $user->individuo->celular,
            "groupName" => "SoftBeleza",
            "postalCode" => $formData['individuo']['individuos_endereco']['cep'],
            "addressNumber" => $formData['individuo']['individuos_endereco']['numero'],
            "notificationDisabled" => true
        ]);

        $tenant = Tenant::find($user->tenant_id);
        $tenant->update([
            'individuo_id' => $individuo->id,
            'asaas_client_id' => $response['id']
        ]);

        DB::commit();
        $usuario = Usuario::with(['individuo', 'estabelecimento', 'controleAcessos', 'tenant'])->find($user->id);
        return response()->json([
            'msg' => 'Cadastro realizado com Sucesso',
            'data' => ['uuid' => $tenant->uuid, 'usuario' => $usuario]
        ]);
    }

    public function checkout(Request $request)
    {
        $formData = $request->all();
        $tenant = Tenant::with('individuo.individuosEndereco')->where('uuid', $formData['uuid'])->first();
        $faturaVencida = Fatura::where([['tenant_id', $tenant->id], ['status_pagamento', 'OVERDUE']])->first();
        $asaasService = new AsaasService();

        if ($formData['tipo_pagamento'] === 'C') { //PAGAMENTO CARTÃO
            $dataVencimento = now()->format('Y-m-d');
            $response = $asaasService->payment([
                'customer' => $tenant->asaas_client_id,
                'billingType' => 'CREDIT_CARD',
                'dueDate' => $dataVencimento,
                'value' => 49.9,
                'description' => "SoftBeleza fatura " . now()->addDay()->format('m/Y'),
                'externalReference' => now()->addDay()->format('m/Y'),
                'creditCard' => [
                    'holderName' => $formData['nome_cc'],
                    'number' => str_replace(' ', '', $formData['numero_cc']),
                    'expiryMonth' => explode('/', $formData['expiracao_cc'])[0],
                    'expiryYear' => explode('/', $formData['expiracao_cc'])[1],
                    'ccv' => $formData['cvv_cc'],
                ],
                'creditCardHolderInfo' => [
                    'name' => $tenant->individuo->nome,
                    'email' => $tenant->individuo->email_contato,
                    'cpfCnpj' => $tenant->individuo->documento,
                    'postalCode' => so_numero($tenant->toArray()['individuo']['individuos_endereco']['cep']),
                    'addressNumber' => $tenant->toArray()['individuo']['individuos_endereco']['numero'],
                    'addressComplement' => null,
                    'phone' => $tenant->individuo->celular,
                    'mobilePhone' => $tenant->individuo->celular,
                ]
            ]);

            if (isset($response['errors']) && count($response['errors'])) {
                return response()->json(['success' => false, 'msg' => $response['errors'][0]['description']]);
            }

            $dataFatura = [
                'tenant_id' => $tenant->id,
                'data_vencimento' => $dataVencimento,
                'tipo_pagamento' => $formData['tipo_pagamento'],
                'data_pagamento' => now()->format('Y-m-d'),
                'transacao_id' => $response['id'],
                'status_pagamento' => $response['status'],
                'bandeira_cartao' => $response['creditCard']['creditCardBrand'],
                'final_cartao' => $response['creditCard']['creditCardNumber'],
                'valor' => $response['value']
            ];

            if ($faturaVencida) {
                $faturaVencida->update($dataFatura);
            } else {
                Fatura::create($dataFatura);
            }

            $tenant->update([
                'cartao_token' => $response['creditCard']['creditCardToken'],
                'tipo_pagamento' => $formData['tipo_pagamento'],
                'dia_vencimento' => now()->format('d')
            ]);

            return response()->json(['success' => true, 'msg' => 'Pagamento realizado!']);
        } else if ($formData['tipo_pagamento'] === 'B') { // PAGAMENTO POR BOLETO
            $dataVencimento = proximo_dia_util()->format('Y-m-d');
            $response = $asaasService->payment([
                'customer' => $tenant->asaas_client_id,
                'billingType' => 'BOLETO',
                'dueDate' => $dataVencimento,
                'value' => 49.9,
                'description' => "SoftBeleza fatura " . proximo_dia_util()->format('m/Y'),
                'externalReference' => proximo_dia_util()->format('m/Y'),
            ]);

            if (isset($response['errors']) && count($response['errors'])) {
                return response()->json(['success' => false, 'msg' => $response['errors'][0]['description']]);
            }

            $dataFatura = [
                'tenant_id' => $tenant->id,
                'data_vencimento' => $dataVencimento,
                'tipo_pagamento' => $formData['tipo_pagamento'],
                'data_pagamento' => null,
                'transacao_id' => $response['id'],
                'status_pagamento' => $response['status'],
                'url_boleto' => $response['bankSlipUrl'],
                'valor' => $response['value']
            ];

            if ($faturaVencida) {
                $faturaVencida->update($dataFatura);
            } else {
                Fatura::create($dataFatura);
            }

            $tenant->update([
                'tipo_pagamento' => $formData['tipo_pagamento'],
                'dia_vencimento' => proximo_dia_util()->format('d')
            ]);

            return response()->json(['success' => true, 'msg' => 'Boleto gerado com sucesso!', 'data' => [
                'url_boleto' => $response['bankSlipUrl']
            ]]);
        }
    }

    public function verificaCheckout($uuid)
    {
        $tenant = Tenant::where('uuid',$uuid)->first();
        $faturas = Fatura::where([['tenant_id', $tenant->id]])->get()->toArray();
        $faturasVencidas = Fatura::where([['tenant_id', $tenant->id], ['status_pagamento', 'OVERDUE']])->get()->toArray();
        if (count($faturas)) {
            $cobrar = false;
            if (count($faturasVencidas)) {
                $cobrar = true;
            }
        } else {
            $cobrar = true;
        }

        return response()->json(compact('cobrar'));
    }
}
