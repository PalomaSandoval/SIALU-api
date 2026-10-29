<?php

namespace App\Http\Controllers;

use App\Contracts\LdapGateway;
use Illuminate\Http\Request;

class UsuarioLdapController extends Controller
{
    public function consultar(Request $request, LdapGateway $ldap)
    {
        $data = $request->validate(['uid' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9!_]+$/']]);
        $user = $ldap->findUser(strtolower(trim($data['uid'])));
        abort_if($user === null, 404, 'El usuario LDAP solicitado no existe.');

        return response()->json(['data' => $user]);
    }
}
