@extends('layouts.app')

@section('title', 'Editar Oficina')

@section('content')
@include('catalogos.oficinas._form', ['item' => $item])
@endsection
