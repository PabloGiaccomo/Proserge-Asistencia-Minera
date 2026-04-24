@extends('layouts.app')

@section('title', 'Editar Mina')

@section('content')
@include('catalogos.minas._form', ['item' => $item])
@endsection
