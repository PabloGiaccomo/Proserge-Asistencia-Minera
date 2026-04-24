@extends('layouts.app')

@section('title', 'Nueva Oficina')

@section('content')
@include('catalogos.oficinas._form', ['item' => $item])
@endsection
