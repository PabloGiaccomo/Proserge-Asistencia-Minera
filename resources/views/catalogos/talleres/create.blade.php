@extends('layouts.app')

@section('title', 'Nuevo Taller')

@section('content')
@include('catalogos.talleres._form', ['item' => $item])
@endsection
