@extends('layouts.app')

@section('title', 'Editar Taller')

@section('content')
@include('catalogos.talleres._form', ['item' => $item])
@endsection
